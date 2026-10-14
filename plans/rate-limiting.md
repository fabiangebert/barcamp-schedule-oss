# Rate Limiting Implementation Plan

## Problem

No request-level rate limiting exists. The only protection is per-email token counting in `Auth::generateMagicToken()`. An attacker can flood any endpoint (magic-link emails, uploads, exports, public schedule reads, file downloads) without restriction.

## Design Decisions

**Storage:** Database table via Doctrine DBAL (consistent with existing stack — no Redis dependency). FrankenPHP workers persist in-memory state, but a DB table is the only approach that works across workers and survives restarts.

**IP resolution:** FrankenPHP's `trusted_proxies static private_ranges` in the Caddyfile already rewrites `REMOTE_ADDR` to the real client IP when behind a trusted proxy. The middleware reads `$request->getServerParams()['REMOTE_ADDR']` — no manual `X-Forwarded-For` parsing needed.

**Algorithm:** Fixed-window counter per key (IP + route group) stored in a single `rate_limits` table. Simple, predictable, and efficient for SQLite. Window granularity = 1 minute.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `migrations/VersionYYYYMMDD000000.php` | **Create** | Add `rate_limits` table |
| `src/RateLimitService.php` | **Create** | Core logic: check/increment/cleanup |
| `src/RateLimitMiddleware.php` | **Create** | PSR-15 middleware wrapping the service |
| `src/App.php` | **Modify** | Register global rate-limit middleware + per-route stricter limits |
| `tests/RateLimitTest.php` | **Create** | PHPUnit tests for the service and middleware |

## 1. Database Migration

```sql
CREATE TABLE rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash VARCHAR(64) NOT NULL,
    window_start INTEGER NOT NULL,
    hits INTEGER NOT NULL DEFAULT 1,
    UNIQUE(key_hash, window_start)
);
CREATE INDEX idx_rate_limits_cleanup ON rate_limits(window_start);
```

- `key_hash` = SHA-256 of `"{client_ip}:{route_group}"` (e.g., `"192.168.1.5:auth"`, `"10.0.0.1:global"`)
- `window_start` = Unix timestamp floored to the current minute
- The `UNIQUE` constraint enables atomic upserts via `INSERT OR REPLACE` / `ON CONFLICT`

## 2. `RateLimitService` (`src/RateLimitService.php`)

Static methods (matches existing codebase pattern — `Auth`, `Database`, `Schedule` are all static):

```php
class RateLimitService
{
    public static function check(string $key, int $maxRequests, int $windowSeconds): array
    // Returns ['allowed' => bool, 'remaining' => int, 'resetAt' => int]
    // Uses atomic INSERT + UPDATE to increment counter
    // On first hit in window: INSERT; on subsequent: UPDATE hits = hits + 1

    public static function cleanup(int $olderThanSeconds): int
    // Deletes rows where window_start < now - olderThanSeconds
    // Called probabilistically (1% of requests) to avoid cleanup overhead
}
```

## 3. `RateLimitMiddleware` (`src/RateLimitMiddleware.php`)

Follows the exact pattern of `AuthMiddleware` and `DatabaseMiddleware`:

```php
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(int $maxRequests, int $windowSeconds, string $routeGroup)
    // $routeGroup = 'global', 'auth', 'upload', 'export', 'public-read', 'download'

    public function process(Request $request, RequestHandler $handler): Response
    // 1. Resolve client IP from $request->getServerParams()['REMOTE_ADDR']
    // 2. Call RateLimitService::check("{ip}:{routeGroup}", max, window)
    // 3. If !allowed: return 429 with Retry-After + X-RateLimit-* headers
    // 4. If allowed: set X-RateLimit-* headers, call handler
    // 5. Probabilistic cleanup (1% chance)
}
```

**Response headers on success:**
- `X-RateLimit-Limit: 100`
- `X-RateLimit-Remaining: 97`
- `X-RateLimit-Reset: 1721913600`

**Response on 429:**
```json
{"status": "failure", "error": "Rate limit exceeded. Try again in X seconds."}
```
With headers: `Retry-After: 42`, `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0`, `X-RateLimit-Reset`

## 4. Rate Limit Tiers

Applied via middleware registration in `App.php`:

| Tier | Route Group | Max Requests | Window | Applied To |
|------|-------------|-------------|--------|------------|
| **Global** | `global` | 120 | 1 min | All routes (safety net) |
| **Auth** | `auth` | 10 | 1 min | `POST /auth/magic-link` |
| **Login** | `login` | 20 | 1 min | `GET /auth/login` |
| **Upload** | `upload` | 20 | 1 min | `POST /schedules/{id}/upload` |
| **Export** | `export` | 5 | 1 min | `GET /schedules/{id}/export`, `GET /schedules/{id}/feedback/export/csv` |
| **Public Read** | `public-read` | 60 | 1 min | `GET /schedules/{id}` (public) |
| **Download** | `download` | 30 | 1 min | `GET /uploads/{filename}` |

## 5. Registration in `App.php`

```php
// Global rate limit (outermost — applies to everything)
$app->add(new RateLimitMiddleware(120, 60, 'global'));

// Then existing middleware: body parsing, routing, DB, error

// Auth routes — stricter
$group->post('/auth/magic-link', ...)->add(new RateLimitMiddleware(10, 60, 'auth'));
$group->get('/auth/login', ...)->add(new RateLimitMiddleware(20, 60, 'login'));

// Upload endpoint — per-route
$guestGroup->post('/{id}/upload', ...)->add(new RateLimitMiddleware(20, 60, 'upload'));

// Export endpoints — per-route
$userGroup->get('/{id}/export', ...)->add(new RateLimitMiddleware(5, 60, 'export'));
$userGroup->get('/{id}/feedback/export/csv', ...)->add(new RateLimitMiddleware(5, 60, 'export'));
```

The global middleware goes on `$app` first (registered before body parsing/routing so it runs last in the Slim middleware stack — i.e., it's the outermost layer that fires after routing resolves). Actually, in Slim 4, middleware added via `$app->add()` runs in LIFO order. So the global rate limit should be added last to run first:

```php
// At the end of App::create(), before return:
$app->add(new RateLimitMiddleware(120, 60, 'global'));
```

## 6. Cleanup Strategy

- Probabilistic: each request has a 1% chance of triggering `RateLimitService::cleanup()`
- Deletes all `rate_limits` rows older than 2x the longest window (120 seconds)
- For SQLite, this is a single `DELETE WHERE window_start < ?` — fast even without indexes on large tables, but the `idx_rate_limits_cleanup` index ensures it stays fast

## 7. Tests (`tests/RateLimitTest.php`)

- `testAllowsRequestUnderLimit` — 5 requests with limit 10 → all pass
- `testBlocksRequestOverLimit` — 11th request with limit 10 → 429
- `testWindowResetsAfterExpiry` — Hit limit, wait/simulate window expiry, request succeeds
- `testDifferentIpsHaveSeparateLimits` — Two IPs hit the same route group independently
- `testDifferentRouteGroupsAreSeparateLimits` — Same IP, different route groups, independent counters
- `testResponseHeadersPresent` — Verify X-RateLimit-* headers on success and 429
- `testRetryAfterHeader` — Verify Retry-After on 429
- `testCleanupRemovesOldEntries` — Insert old rows, call cleanup, verify deleted

## 8. i18n

Add to `frontend/src/locales/de.json` and `en.json`:
```json
"error.rateLimitExceeded": "Zu viele Anfragen. Bitte versuchen Sie es später erneut."
"error.rateLimitExceeded": "Too many requests. Please try again later."
```

If the frontend displays the 429 error message from the API, use the i18n key. The API itself returns English (consistent with existing error responses).

## Open Questions

1. **Threshold values** — The numbers above (120 global, 10 auth, 20 upload, 5 export, 60 public-read, 30 download) are conservative defaults. Adjust based on expected traffic.
2. **No external dependency** — This is a pure DB-based solution with no new Composer packages. The tradeoff is slightly more SQL code vs. pulling in something like `symfony/rate-limiter`.
3. **Global middleware placement** — The global rate limit middleware needs to run before everything else. In Slim 4's LIFO stack, that means adding it last in `App::create()`. The per-route limits are added to specific route definitions.
