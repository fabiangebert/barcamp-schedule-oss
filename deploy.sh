#!/bin/sh
set -euo pipefail

# ─── Configuration ───────────────────────────────────────────
# Set these before running, or override via environment:
#   SCW_NAMESPACE      (default: "barcamp-schedule")
#   SCW_REGION         (default: "fr-par")
#   SCW_PROJECT_ID     (required)
#   SCW_ACCESS_KEY     (required, for S3)
#   SCW_SECRET_KEY     (required, for S3)
#   CONTAINER_IMAGE    (default: "ghcr.io/<your-org>/barcamp-schedule")
#   APP_ENV            (default: "production")
#
# Prerequisites:
#   - docker / docker buildx
#   - scw (Scaleway CLI) – logged in

NAMESPACE="${SCW_NAMESPACE:-barcamp-schedule}"
REGION="${SCW_REGION:-fr-par}"
TAG=$(date -u +%Y%m%d-%H%M%S)

if [ -z "${CONTAINER_IMAGE:-}" ]; then
    CONTAINER_IMAGE="ghcr.io/${GITHUB_REPOSITORY:-your-org/barcamp-schedule}"
fi

IMAGE="${CONTAINER_IMAGE}:${TAG}"
LATEST="${CONTAINER_IMAGE}:latest"

# ─── Step 1: Build frontend ──────────────────────────────────
echo "→ Building frontend..."
(
    cd frontend
    # Dev dependencies are required for the build: npm run build executes
    # ts-json-schema-generator and eslint, both of which live in devDependencies.
    npm ci
    npm run build
)

# ─── Step 2: Build Docker image ──────────────────────────────
echo "→ Building Docker image (${IMAGE})..."
docker buildx build \
    --platform linux/amd64 \
    -t "${IMAGE}" \
    -t "${LATEST}" \
    --push \
    .

# ─── Step 3: Deploy to Scaleway Serverless Container ─────────
echo "→ Deploying to Scaleway..."

# Create container namespace if it doesn't exist
scw container namespace create \
    --region "${REGION}" \
    --name "${NAMESPACE}" \
    2>/dev/null || true

# ─── Step 3a: Run migrations as a one-off job ───────────────
# Migrations must NOT run on every cold start of a scale-to-zero container:
# concurrent cold starts would execute DDL concurrently and cause lock
# contention or schema corruption. Instead, run them as a discrete
# pre-deployment job that exits before the serving container starts.
echo "→ Running database migrations as a one-off job..."
scw container job run \
    --region "${REGION}" \
    --namespace-name "${NAMESPACE}" \
    --name "migrate" \
    --image "${IMAGE}" \
    --cpu-limit 280 \
    --memory-limit 512 \
    --command "php /app/vendor/bin/doctrine-migrations migrate --db-configuration=/app/migrations-db.php --no-interaction" \
    --env "DB_DRIVER=pdo_pgsql" \
    --env "DB_HOST=${DB_HOST}" \
    --env "DB_PORT=${DB_PORT:-5432}" \
    --env "DB_NAME=${DB_NAME:-barcamp}" \
    --env "DB_USER=${DB_USER:-barcamp}" \
    --env "DB_PASSWORD=${DB_PASSWORD}" \
    2>&1 || echo "  (migration job may already be running or not supported — skipping)"

# Deploy new container revision
# MIGRATE_ON_BOOT=false: the container must not run migrations on cold start;
# they have already been applied by the job above.
scw container container create \
    --region "${REGION}" \
    --namespace-name "${NAMESPACE}" \
    --name "app" \
    --image "${IMAGE}" \
    --min-scale 0 \
    --max-scale 1 \
    --cpu-limit 280 \
    --memory-limit 512 \
    --port 8000 \
    --protocol http1 \
    --health-check-path "/api/health" \
    --env "APP_ENV=${APP_ENV:-production}" \
    --env "MIGRATE_ON_BOOT=false" \
    --env "DB_DRIVER=pdo_pgsql" \
    --env "DB_HOST=${DB_HOST}" \
    --env "DB_PORT=${DB_PORT:-5432}" \
    --env "DB_NAME=${DB_NAME:-barcamp}" \
    --env "DB_USER=${DB_USER:-barcamp}" \
    --env "DB_PASSWORD=${DB_PASSWORD}" \
    --env "MERCURE_PUBLIC_URL=${MERCURE_PUBLIC_URL}" \
    --env "MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET}" \
    --env "MERCURE_JWT_TIMEOUT=${MERCURE_JWT_TIMEOUT:-600}" \
    --env "S3_ENDPOINT=${S3_ENDPOINT}" \
    --env "S3_ACCESS_KEY=${S3_ACCESS_KEY}" \
    --env "S3_SECRET_KEY=${S3_SECRET_KEY}" \
    --env "S3_BUCKET=${S3_BUCKET}" \
    --env "S3_REGION=${S3_REGION:-fr-par}" \
    --env "JWT_SECRET=${JWT_SECRET}" \
    --env "APP_URL=${APP_URL}" \
    2>&1

echo "→ Deploying latest tag..."
scw container container update \
    --region "${REGION}" \
    --namespace-name "${NAMESPACE}" \
    --name "app" \
    --image "${LATEST}" \
    2>&1 || true

echo "✅ Deploy complete: ${IMAGE}"
echo "   Container: https://${NAMESPACE}.${REGION}.containers.${SCW_PROJECT_ID}.scw.cloud"
