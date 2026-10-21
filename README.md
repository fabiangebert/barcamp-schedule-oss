# Barcamp Schedule

A modern, lean implementation of a schedule editor for BarCamps, tracks, and community events — built with **Vue 3** and **Slim Framework (FrankenPHP)**.

The application is containerised and optimised for a **Fully Decoupled Serverless Architecture** on Scaleway: all read-only assets (the Vue SPA, uploaded documents, and compiled schedule JSONs) are served statically from Object Storage at the edge, so the backend container is only woken up for dynamic state-mutating requests (authentication, schedule edits). See [DEPLOYMENT.md](./DEPLOYMENT.md) for the full architecture and production guide.

## Features

- **Vue 3 Frontend**: Reactive, component-based UI with a grid-style schedule editor.
- **Slim/FrankenPHP Backend**: Lightweight, high-performance PHP API.
- **Real-time Updates**: Server-Sent Events (SSE) via an external Mercure Hub keep all open tabs in sync. See [Real-Time Architecture](./docs/architecture/realtime-updates.md).
- **Magic Link Authentication**: Email-based login without passwords, protected by per-email anti-spam/rate limiting.
- **Optimistic Locking**: Versioning prevents concurrent edit conflicts.
- **Documentation Uploads**: Attach images and PDFs with dual-layer security validation (extension + content-based `finfo` MIME check) and **ahead-of-time downscaling to WebP** for images.
- **Read-only Share Links**: Share-link tokens give participants read-only access with real-time updates and optional expiration.
- **Multi-Database**: Supports PostgreSQL, MySQL, and SQLite via Doctrine DBAL abstraction.
- **S3 Object Storage**: Frontend, uploads, and schedule JSONs can be served entirely from Object Storage at the edge.
- **Responsive Layout**: Replicates the original CSS grid-based design.

## Architecture Overview

```
                                  User Browser
                                        │
                       ┌────────────────┴────────────────┐
                       │                                 │
               GET index.html, JS, CSS           POST /PATCH /DELETE
               GET /api/uploads/*                GET /api/schedules/subscription
               GET /api/schedules/{id}           (Auth, edits, SSE tokens)
                       │                                 │
             ┌─────────▼─────────┐              ┌────────▼─────────┐
             │   Edge Layer     │               │   Edge Layer    │
             │  (Cloudflare)    │               │  (Cloudflare)   │
             └─────────┬─────────┘              └────────┬─────────┘
                       │                                 │
             ┌─────────▼─────────┐              ┌────────▼─────────┐
             │  Object Storage   │              │ Serverless App   │
             │   (S3 / Edge)     │              │    Container     │
             │                   │              │                  │
             │ /dist/ (SPA)      │              │ Only handles     │
             │ /uploads/*        │              │ dynamic writes   │
             │ /schedules/*      │              │ and auth.        │
             │  0ms cold starts  │              │ Scales to 0 when │
             └───────────────────┘              │ idle (0 cost).   │
                                                └──────────────────┘
```

Static reads are served from S3/CDN (zero cold start), while the Serverless App Container handles only authentications and schedule modifications, scaling to zero when idle. The Mercure Hub runs on a dedicated VM because Scaleway Serverless Containers cap concurrent connections. Full details: [DEPLOYMENT.md](./DEPLOYMENT.md).

## Prerequisites

- PHP 8.4.27+ (security floor for CVE-2026-109618/109619; keep patch versions current)
- Composer
- Node.js 18+ and npm
- Docker (for the local dev environment with Mercure Hub)

## Quick Start (Development)

> The following runs a **local development** environment (container-served frontend + Mercure Hub). For the production serverless deployment, follow [DEPLOYMENT.md](./DEPLOYMENT.md).

### Using Docker (recommended)

```bash
docker compose up -d
```

- Backend API: http://localhost:8000
- Frontend: http://localhost:5173
- Mercure Hub: http://localhost:8080/.well-known/mercure

The first build takes a few minutes. Source directories are mounted so PHP and frontend changes are reflected immediately.

## Configuration

### Backend Configuration

The backend uses environment variables for configuration. Set them via `export` (or your hosting platform's env var UI).

| Variable | Description | Default |
|:---|---:|:---|
| `DB_DRIVER` | Database driver: `pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`. | `pdo_sqlite` |
| `DB_PATH` | Path to SQLite file (only for `pdo_sqlite`). | `database.sqlite` |
| `DB_HOST` | Database host (for `pdo_mysql` and `pdo_pgsql`). | `localhost` |
| `DB_PORT` | Database port (for `pdo_mysql`/`pdo_pgsql`). | `3306` / `5432` |
| `DB_NAME` | Database name (for `pdo_mysql` and `pdo_pgsql`). | `barcamp` |
| `DB_USER` | Database user (for `pdo_mysql` and `pdo_pgsql`). | `barcamp` / `root` |
| `DB_PASSWORD` | Database password (for `pdo_mysql` and `pdo_pgsql`). | — |
| `DB_SSLMODE` | SSL mode for PostgreSQL connections (`prefer`, `require`, etc.). Required for Scaleway Serverless SQL. | `prefer` |
| `ADMIN_EMAIL` | **(Required)** The email address of the initial admin user. | `admin@example.com` |
| `JWT_SECRET` | Secret key for generating JWTs (32+ chars). | `super-secret-key-that-is-at-least-32-chars` |
| `JWT_EXPIRY_SECONDS` | Expiration time for login JWTs in seconds. | `86400` (24 hours) |
| `APP_URL` | Base URL of the application for magic links. | `http://localhost:5173` |
| `API_CORS_ORIGIN` | Allowed origin(s) for backend CORS requests. Supports multiple space-separated origins (or `*`). Public route (`/api/schedules/*`) is always open to all origins (`*`). | `*` |
| `APP_ENV` | `development` or `prod`. | `development` |
| `APP_DEBUG` | Enable or disable debug mode. | `true` |
| `UPLOADS_DIR` | Absolute path to the uploads directory. | `uploads` |
| `MIGRATE_ON_BOOT` | Whether to run database migrations on container boot. Disable in production to avoid lock contention (see DEPLOYMENT.md). | `true` |
| `MAX_REQUESTS` | Maximum requests per second/IP enforced by the API rate limiter. | `500` |
| `MERCURE_URL` | URL of the Mercure Hub for publishing updates. | `http://localhost:8080/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | Publicly accessible URL for Mercure (used by clients). | `http://localhost:8080/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | Secret key for generating Mercure JWTs (32+ chars). | `change-me-32-char-secret-for-testing!!!` |
| `MERCURE_JWT_TIMEOUT` | Expiration timeout for Mercure JWTs in seconds. | `900` (15 minutes) |
| `SMTP_DSN` | SMTP connection string. | `sendmail://default` |
| `MAIL_FROM` | Email address to use as sender. | `noreply@example.com` |
| `MAIL_SUBJECT` | Subject line for the magic link email. | `Your Magic Login Link` |
| `MAIL_BODY` | Custom body for the magic link email. | `Custom Body {link}` |
| `S3_ENDPOINT` | S3-compatible object storage endpoint (Scaleway, etc.). | — |
| `S3_REGION` | Object storage region (e.g. `fr-par`). | — |
| `S3_ACCESS_KEY` | Object storage access key. | — |
| `S3_SECRET_KEY` | Object storage secret key. | — |
| `S3_BUCKET` | Object storage bucket name. | — |
| `PHP_WORKER_COUNT` | Number of persistent FrankenPHP workers. Lower on resource-constrained containers. | `2` |
| `PHP_MEMORY_LIMIT` | Maximum memory per PHP request. | `256M` |
| `PHP_OPCACHE_MEMORY_CONSUMPTION` | Memory (MB) allocated to the PHP OPcache engine. | `64` |

### Frontend Configuration

The frontend uses build-time environment variables (prefixed with `VITE_`) during the build process.

| Variable | Description | Default |
|:---|---:|:---|
| `VITE_APP_TITLE` | Title of the application shown in the frontend. | `Barcamp Schedule` |
| `VITE_API_BASE` | API base URL for the frontend. | `/api` |
| `VITE_MAX_UPLOAD_SIZE` | Maximum file size for image uploads in bytes. | `10485760` (10MB) |
| `VITE_TAB_STALENESS_THRESHOLD` | Threshold in milliseconds to detect a stale browser tab. | `60000` (60 seconds) |
| `VITE_PROXY_TARGET` | Target API backend URL used during development local proxying. | `http://localhost:8000` |

In CI, `VITE_APP_TITLE` and `VITE_API_BASE` are read from the GitHub repository variables of the same names (Settings → Secrets and variables → Actions → Variables), falling back to the defaults above when unset.

## Database Quick Start

The app supports three database drivers via `DB_DRIVER`:

### SQLite (default, no external service needed)
```bash
docker compose -f docker-compose.yml -f docker-compose.sqlite.yml up -d
```

### PostgreSQL
```bash
docker compose up -d
```

### MySQL
```bash
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up -d
```

### Seed demo data
```bash
php seed.php
```
Runs all pending migrations and creates an admin user, a standard user, and a demo schedule.

## Usage

### Viewing the Schedule
The default view is the **Viewer**. It updates in real time as changes are made via Mercure SSE. Public viewers receive static, read-only access; authenticated users and share-link guests receive real-time updates.

### Editing the Schedule
1. Enter your email in the header and click **Login via Magic Link**.
2. In `development` mode, the magic link token is returned in the API response. Use the link or append `?token=YOUR_TOKEN` to the URL.
3. Once logged in, click **Edit Mode**.
4. Click any field (Room name, Timeslot, Session title, etc.) to edit it directly.
5. To **delete** a Room, Timeslot, or Session, change its value to `---`.
6. Click **Save** to persist your changes.

### Share Links
Share links provide read-only access to the schedule with **optional expiration** (7 days by default). They are useful for participants who don't need to edit the schedule and receive real-time updates without logging in.

### Documentation
Admins and editors can attach documentation (images and PDFs) to schedules. Uploads are validated by extension **and** content-based MIME type; image uploads are automatically downscaled to WebP.

## Testing

### Backend (PHPUnit)
```bash
composer test
```

### Frontend (Vitest)
```bash
cd frontend
npm run test
```

### E2E (Playwright)
```bash
cd frontend
npm run test:e2e
```

The E2E test runner automatically seeds a test database, starts the PHP and Vite dev servers, runs the Playwright suite, and restores the original database.

## Schedule Data Validation (JSON Schema)

The backend validates schedule data against a JSON Schema **auto-generated from the frontend TypeScript types** to prevent drift.

- **Source of truth**: `frontend/src/types/schedule.ts` defines `ScheduleData`, `Room`, `Timeslot`, `Session`, `Topic`, `SizeCategory`.
- **Regenerate**: run `composer generate:schema` (delegates to `ts-json-schema-generator`), which writes to `backend/resources/schedule.schema.json`.
- **When**: any time `schedule.ts` is modified. A PHPUnit test (`ScheduleSchemaUpToDateTest`) detects drift and fails if the schema is stale.
- **Validation**: `POST /schedules` and `PATCH /schedules/{id}` validate incoming data and return `400` with field-level `details` on failure.

## Real-Time Architecture

Real-time updates are powered by [Mercure](https://mercure.rocks/), an external hub that receives schedule change broadcasts (full state + version) and streams them to subscribers via Server-Sent Events. The implementation does not rely on Mercure history — clients recover via a REST staleness detector. Full details: [Real-Time Architecture: Mercure Usage](./docs/architecture/realtime-updates.md).

### Local testing with Docker
```bash
docker run --rm -p 8080:80 \
  -e SERVER_NAME=":80" \
  -e MERCURE_PUBLISHER_JWT_KEY="change-me-32-char-secret-for-testing!!!" \
  -e MERCURE_SUBSCRIBER_JWT_KEY="change-me-32-char-secret-for-testing!!!" \
  -e ALLOW_ANONYMOUS="1" \
  -e CORS_ALLOWED_ORIGINS="http://localhost:5173 http://localhost:8000 http://localhost" \
  dunglas/mercure:v0.24.2
```

Or use the docker-compose dev environment (`docker compose up`), which includes a Mercure service automatically.

## Database Migrations

This project uses [Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html).

### Running Migrations
```bash
vendor/bin/doctrine-migrations migrate --db-configuration=migrations-db.php --no-interaction
```

To create a new migration:
```bash
vendor/bin/doctrine-migrations generate --db-configuration=migrations-db.php
```

Migrations are stored in the `migrations/` directory.

## Production Deployment

See **[DEPLOYMENT.md](./DEPLOYMENT.md)** for the complete production guide covering the zero-wakeup serverless architecture, Scaleway Object Storage (S3) setup, the dedicated Mercure Hub VM, database migration jobs, edge routing rules, environment variables, and performance tuning.

## Documentation & Architecture Index

- [DEPLOYMENT.md](./DEPLOYMENT.md) — Production serverless deployment guide.
- [docs/architecture/realtime-updates.md](./docs/architecture/realtime-updates.md) — Real-time (Mercure/SSE) architecture.
- [docs/adr/](./docs/adr/) — Architecture Decision Records (e.g. native `DB_SSLMODE`).
- [AGENTS.md](./AGENTS.md) — Project conventions and guidelines for AI agents / contributors.
- [CONTRIBUTING.md](./CONTRIBUTING.md) — Contribution guidelines.
