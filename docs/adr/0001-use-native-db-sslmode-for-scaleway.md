# Architecture Decision Record (ADR): Native DB_SSLMODE for Scaleway Serverless SQL

## Context
When deploying the application using a Scaleway Serverless SQL PostgreSQL database, the container failed to boot with the following fatal error:
`Database hostname wasn't sent to server. Either add "?options=databaseid%3D{databaseid}" at the end of your connection string, or use another client supporting TLS ServerNameIndication.`

This error occurs because Scaleway's Serverless SQL databases use a connection pooler/routing proxy that relies on TLS Server Name Indication (SNI) during the handshake to route traffic to the appropriate serverless tenant.

Initially, a workaround to inject `options=databaseid=...` into the connection DSN string was considered. However, further research into PostgreSQL's native C client library (`libpq`) revealed:
1. Native TLS SNI support is standard in `libpq` version 14 and newer.
2. Since our application runs on PHP 8.4 inside a modern Alpine-based container (`php:8.4-fpm-alpine`), our runtime environment has native access to `libpq` 16+ (which is well above version 14).
3. Therefore, the physical capabilities to send SNI natively exist out-of-the-box. The connection was only failing because it defaulted to unencrypted traffic, preventing the TLS handshake from being initiated.

## Decision
Instead of using custom DSN query-parameter parsing hacks or appending `options=databaseid` values, we will use native TLS/SSL enforcement to solve the issue on the protocol level.

We will:
1. Revert any `options=databaseid` or `DB_DSN_EXTRA` DSN injection hacks.
2. Introduce a new, standard `DB_SSLMODE` environment variable.
3. Configure the default SSL mode to `prefer` so it does not disrupt local development environments (where local PostgreSQL docker containers typically run without TLS certificates configured).
4. Inform operators in `DEPLOYMENT.md` to configure `DB_SSLMODE=require` when deploying to Scaleway.

## Implementation Details
- **`src/Database.php`**: Integrated the native `sslmode` parameter for Doctrine DBAL's PgSQL driver, falling back to `prefer` if `DB_SSLMODE` is not set:
  ```php
  'sslmode' => getenv('DB_SSLMODE') ?: 'prefer',
  ```
- **`docker-entrypoint.sh` & `migrate-sqlite-to-pgsql.php`**: Cleanly appended `sslmode` directly to the raw PDO PostgreSQL connection string.

## Consequences
- **Security**: Promotes industry standard encrypted database connections (`sslmode=require`) in production environments.
- **Maintainability**: Avoids custom string manipulation logic, regex parsers, or non-standard class extensions.
- **Portability**: Uses standard environment variables compatible with any standard PostgreSQL/libpq environment.
