# Technical Debt

## `API_CORS_ORIGIN` Naming and Behavior
The environment variable `API_CORS_ORIGIN` is misleading. The `Caddyfile` currently hardcodes the `https://` scheme, meaning the variable expects a list of domains (without schemes). However, the name "ORIGIN" typically implies a full origin string (including the scheme, e.g. `https://example.com`), which leads to configuration errors.

**Action:** Rename the variable to `API_CORS_DOMAINS` (or similar) to accurately reflect that it takes a list of domains. Update `docker-entrypoint.sh`, `Caddyfile`, `DEPLOYMENT.md`, and any other documentation/configuration to reflect the new semantics.

## `API_BASE` Naming and Backend-Returned Path Coupling
Currently, the environment variable `API_BASE` (and `VITE_API_BASE`) is configured to include the `/api` routing prefix (e.g., `https://schedule-app.example.com/api` or `/api`), which tightly couples the API host domain representation with its specific routing namespace.

Additionally, the backend returns relative path fragments (like `/api/uploads/5783f3b90fec28c5.jpg`) and stores them directly in the database `documentation` tables. When the Vue SPA is embedded as a Web Component on an external hosting domain, any relative path starting with `/` (like `/api/uploads/...`) is resolved by the browser against the embedding hosting domain (e.g., `https://schedule.example.com/api/uploads/...`) instead of the API server domain. This forces a fragile string replacement in the frontend (`img.replace(/^\/api/, API_BASE)`).

**Action:**
1. **Decouple database storage from routing**: Modify the backend to only return and store the file name (or a clean relative upload path) rather than the `/api/uploads/...` routing fragment.
2. **Standardize API Base Host**: Refactor `API_BASE` to only represent the absolute base domain host of the backend server (e.g., `https://schedule-app.example.com`), and explicitly define `/api` as part of the request routing logic inside frontend requests. This will enable clean, robust URL reconstruction on the client side.

## Remove `/api` Route Prefix
Currently, the backend API is served under the `/api` prefix. Given the zero-wakeup serverless architecture (as outlined in `DEPLOYMENT.md`), the Serverless Container is fully decoupled and dedicated solely to serving the API (while static assets and the Vue SPA are served via S3/CDN). Therefore, having an `/api` prefix inside the backend container is redundant.

**Action:** Refactor the routing to serve the API directly under `/` instead of `/api`. This will simplify the `Caddyfile` routing, frontend proxies, and Slim framework configuration.

## Divergent Token Sources in the Frontend
The JWT is read from two different places. Most requests use the in-memory `token` ref (`frontend/src/App.vue:462`, `:603`, `:632`), but `frontend/src/components/documentation/DocumentationOverview.vue:272` reads `localStorage.getItem('token')` directly. These can diverge: the axios response interceptor renews a sliding session and writes the new token to `localStorage` only (`frontend/src/main.js:27-29`), never updating the `token` ref. After a renewal, `localStorage` holds a newer token than the one most requests send, so two endpoints rendered on the same screen can authenticate as different credentials.

**Action:** Establish a single source of truth for the token. Have the interceptor update the `token` ref in addition to `localStorage`, and replace the direct `localStorage` reads with the reactive ref so a renewal propagates everywhere at once.

## Local E2E Runs Can Silently Target the Docker Compose Backend
`frontend/playwright.config.js:36` and `:56` set `reuseExistingServer: !process.env.CI`. When the `docker-compose` stack is running, it already occupies ports 8000, 5173 and 8080, so Playwright reuses those servers instead of starting its own. The suite then runs against the container's PostgreSQL database rather than the freshly seeded `database_test.sqlite`, and every schedule lookup returns 404 because the seeded schedule id does not exist there. The result is a wall of failures that looks like a broken application but is actually the harness pointing at the wrong backend.

The related failure mode is invisible in the opposite direction: because the readiness check is skipped when a server is reused, the `url` and `timeout` values of both `webServer` entries are never exercised locally. Both CI-only defects found so far (a readiness URL answering 401, and `VITE_API_BASE` leaking the production URL into the test frontend) were masked this way.

**Action:** Give the E2E servers their own ports so they never collide with the compose stack, or drop `reuseExistingServer` so a stale server can never be silently adopted. Either way, make the harness exercise its own servers on every run, locally and in CI.

## `imagedestroy()` Deprecated Call
`tests/ImageResizeTest.php:35` calls `imagedestroy()`, deprecated since PHP 8.5 because the function has had no effect since PHP 8.0. Production runs PHP 8.4 (`Dockerfile`), so this does not affect the deployed application, but it is the only remaining deprecation in the test suite and will fail once the runtime moves to 8.5.

**Action:** Remove the `imagedestroy($img)` call. PHP frees the image resource when `$img` goes out of scope.

