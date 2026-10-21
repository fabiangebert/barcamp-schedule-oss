# Stage 1: Build frontend (used only to generate schedule.schema.json)
FROM node:22-alpine AS frontend-builder

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/index.html frontend/vite.config.js frontend/eslint.config.js ./
COPY frontend/src/ src/
RUN mkdir -p ../backend/resources
RUN npm run build

# Stage 2: Install system dependencies for PHP extensions
FROM dunglas/frankenphp:php8.4-alpine AS php-deps

# Security gate: abort the build unless the base image ships a PHP release
# containing the PHP2Shell fixes (CVE-2026-109618 / CVE-2026-109619, CVSS 9.2
# unauthenticated RCE; fixed in PHP 8.4.27). FrankenPHP's moving `php8.4-alpine`
# tag can lag behind PHP security releases, so this makes it impossible to
# build or deploy a vulnerable container. Bump PHP_MIN_VERSION with every
# future PHP security release.
ARG PHP_MIN_VERSION=8.4.27
RUN php -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "${PHP_MIN_VERSION}" \
    || { \
        echo "ERROR: base image provides PHP $(php -r 'echo PHP_VERSION;') but >= ${PHP_MIN_VERSION} is required (CVE-2026-109618/109619)."; \
        echo "Run 'docker pull dunglas/frankenphp:php8.4-alpine' and rebuild once FrankenPHP publishes a patched image."; \
        exit 1; \
    }

RUN apk add --no-cache \
        curl \
        ca-certificates \
        freetype libpng libjpeg-turbo libwebp \
        libzip \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        curl-dev \
        freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        zip \
        curl \
        gd \
        pdo_mysql \
        pdo_pgsql \
        opcache \
    && apk del .build-deps

# Stage 3: PHP vendor deps
FROM php-deps AS vendor-builder

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --no-autoloader

# Stage 4: Runtime
FROM php-deps

# Create a non-privileged user to run FrankenPHP.
RUN addgroup -S app && adduser -S -G app app

COPY Caddyfile /etc/caddy/Caddyfile

WORKDIR /app

# Remove default welcome index.php from base image to prevent exposing phpinfo()
RUN rm -f public/index.php

# Temporarily copy composer and config for autoloader optimization
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

COPY src/ src/
COPY public/ public/
COPY migrations/ migrations/
COPY migrations.php migrations-db.php ./

COPY --from=vendor-builder /app/vendor vendor/
COPY --from=frontend-builder /backend/resources/schedule.schema.json backend/resources/schedule.schema.json

# Optimize the autoloader authoritatively and clean up composer files
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm /usr/bin/composer composer.json composer.lock

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Raise PHP upload limits to allow hi-res smartphone photos and uncompressed PDFs
# Environment variables will be expanded at runtime by PHP.
RUN printf 'upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE}\npost_max_size=${PHP_POST_MAX_SIZE}\nmemory_limit=${PHP_MEMORY_LIMIT}\nmax_execution_time=${PHP_MAX_EXECUTION_TIME}\ndisplay_errors=${PHP_DISPLAY_ERRORS}\n' \
        > /usr/local/etc/php/conf.d/zz-uploads.ini

# Configure OPcache using environment variable expansion at runtime.
RUN printf 'opcache.enable=${PHP_OPCACHE_ENABLE}\nopcache.enable_cli=${PHP_OPCACHE_ENABLE}\nopcache.memory_consumption=${PHP_OPCACHE_MEMORY_CONSUMPTION}\nopcache.interned_strings_buffer=${PHP_OPCACHE_INTERNED_STRINGS_BUFFER}\nopcache.max_accelerated_files=${PHP_OPCACHE_MAX_ACCELERATED_FILES}\nopcache.validate_timestamps=${PHP_OPCACHE_VALIDATE_TIMESTAMPS}\n' \
        > /usr/local/etc/php/conf.d/zz-opcache.ini

# Harden the request lifecycle: the app authenticates with JWTs and never
# starts native PHP sessions, so upload progress tracking is unused surface.
# Disabling it removes the confirmed session-lifecycle producer/consumer path
# from the PHP2Shell advisory (CVE-2026-109619) as defense in depth.
RUN printf 'session.upload_progress.enabled=0\n' \
        > /usr/local/etc/php/conf.d/zz-security.ini

# Create writable runtime directories and transfer ownership to app.
# FrankenPHP needs /data/caddy and /config/caddy to store certificates/cache.
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p /data /data/uploads /data/caddy /config/caddy \
    && chown -R app:app /data /app /data/caddy /config/caddy /etc/caddy

USER app

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
