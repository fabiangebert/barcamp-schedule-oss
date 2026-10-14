#!/bin/sh
set -e

# Set sensible default environment variables for a 512MB RAM container
export PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-256M}"
export PHP_UPLOAD_MAX_FILESIZE="${PHP_UPLOAD_MAX_FILESIZE:-100M}"
export PHP_POST_MAX_SIZE="${PHP_POST_MAX_SIZE:-104M}"
export PHP_MAX_EXECUTION_TIME="${PHP_MAX_EXECUTION_TIME:-120}"

if [ "${APP_DEBUG:-false}" = "true" ]; then
    export PHP_DISPLAY_ERRORS="${PHP_DISPLAY_ERRORS:-On}"
else
    export PHP_DISPLAY_ERRORS="${PHP_DISPLAY_ERRORS:-Off}"
fi

# PHP OPcache Settings
export PHP_OPCACHE_ENABLE="${PHP_OPCACHE_ENABLE:-1}"
export PHP_OPCACHE_MEMORY_CONSUMPTION="${PHP_OPCACHE_MEMORY_CONSUMPTION:-64}"
export PHP_OPCACHE_INTERNED_STRINGS_BUFFER="${PHP_OPCACHE_INTERNED_STRINGS_BUFFER:-8}"
export PHP_OPCACHE_MAX_ACCELERATED_FILES="${PHP_OPCACHE_MAX_ACCELERATED_FILES:-10000}"
export PHP_OPCACHE_VALIDATE_TIMESTAMPS="${PHP_OPCACHE_VALIDATE_TIMESTAMPS:-0}"

mkdir -p /data
# Ensure the uploads target exists. Named volumes inherit the image
# directory ownership on first creation, so a fresh barcamp_data volume
# is already owned by app and this just works. If an old root-owned
# volume persists, recreate it once with: docker compose down -v
mkdir -p "${UPLOADS_DIR:-/data/uploads}" 2>/dev/null || true

DB_HOST="${DB_HOST:-localhost}"

case "$DB_DRIVER" in
    pdo_pgsql)
        if [ "${MIGRATE_ON_BOOT:-true}" = "true" ]; then
            echo "Waiting for PostgreSQL..."
            DB_PORT="${DB_PORT:-5432}"
            for i in $(seq 1 30); do
                if php -r "
                    try {
                        new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME:-barcamp};sslmode=' . (getenv('DB_SSLMODE') ?: 'require'),
                            '${DB_USER:-barcamp}', '${DB_PASSWORD:-}');
                        exit(0);
                    } catch (\Exception \$e) {
                        exit(1);
                    }
                " 2>/dev/null; then
                    echo "PostgreSQL is ready."
                    break
                fi
                echo "Waiting for PostgreSQL... (attempt $i/30)"
                sleep 2
            done
        fi
        ;;
    pdo_mysql)
        if [ "${MIGRATE_ON_BOOT:-true}" = "true" ]; then
            echo "Waiting for MySQL..."
            DB_PORT="${DB_PORT:-3306}"
            for i in $(seq 1 30); do
                if php -r "
                    try {
                        new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME:-barcamp}',
                            '${DB_USER:-root}', '${DB_PASSWORD:-}');
                        exit(0);
                    } catch (\Exception \$e) {
                        exit(1);
                    }
                " 2>/dev/null; then
                    echo "MySQL is ready."
                    break
                fi
                echo "Waiting for MySQL... (attempt $i/30)"
                sleep 2
            done
        fi
        ;;
    pdo_sqlite|*)
        DB_DIR=$(dirname "${DB_PATH:-/data/database.sqlite}")
        mkdir -p "$DB_DIR" 2>/dev/null || true
        ;;
esac

# Migrations are opt-in on boot. In production (especially on scale-to-zero
# PaaS where multiple instances can cold-start concurrently), migrations must
# be run as a discrete pre-deployment job to avoid concurrent DDL lock
# contention. Set MIGRATE_ON_BOOT=true (the default) for local/dev only.
if [ "${MIGRATE_ON_BOOT:-true}" = "true" ]; then
    echo "Running database migrations..."
    php /app/vendor/bin/doctrine-migrations migrate \
        --db-configuration=/app/migrations-db.php \
        --no-interaction 2>&1
    echo "Migrations complete."
else
    echo "Skipping migrations (MIGRATE_ON_BOOT=false)."
fi

if [ -n "$API_CORS_ORIGIN" ]; then
    # Escape dots and replace spaces with pipes for regex matching in Caddyfile
    export API_CORS_ORIGIN=$(echo "$API_CORS_ORIGIN" | sed -e 's/\./\\./g' -e 's/ /|/g')
fi

echo "Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
