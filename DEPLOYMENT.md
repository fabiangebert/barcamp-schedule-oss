# Deployment

This project is containerised and optimised for a **Fully Decoupled Serverless Architecture** on Scaleway. 

In a traditional setup, serving the frontend, uploaded attachments, and schedule JSONs from a container requires the container to boot or remain active—negating the cost and scaling benefits of Serverless. 

Instead, this guide configures Scaleway so that **all read-only static files (including the Vue SPA, uploads, and schedules) are served 100% statically from Scaleway Object Storage (S3)** at the edge, meaning your Serverless Container is **only** woken up for dynamic state-mutating API requests (e.g., logins, schedule edits).

---

## 🏢 The Zero-Wakeup Serverless Architecture

```
                                  User Browser
                                        │
                      ┌─────────────────┴─────────────────┐
                      │                                   │
              GET index.html, JS, CSS             POST /PATCH /DELETE
              GET /api/uploads/*                  GET /api/schedules/subscription
              GET /api/schedules/{id}             (Authentications, edits, SSE tokens)
                      │                                   │
            ┌─────────▼─────────┐                       ┌─▼─────────────────┐
            │    Cloudflare     │                       │    Cloudflare     │
            │   (Edge Rules)    │                       │   (Edge Rules)    │
            └─────────┬─────────┘                       └─────────┬─────────┘
                      │                                           │
            ┌─────────▼─────────┐                       ┌─────────▼─────────┐
            │    Scaleway S3    │                       │    Scaleway App   │
            │  (Bucket Website) │                       │     Container     │
            │                   │                       │                   │
            │   /dist/ (SPA)    │                       │  Only handles     │
            │   /uploads/*      │                       │  dynamic writes   │
            │   /schedules/*    │                       │  and auth.        │
            │                   │                       │  Scales to 0 when │
            │  0ms cold starts! │                       │  idle (0 cost!).  │
            └───────────────────┘                       └───────────────────┘
```

By placing an Edge routing layer (like **Cloudflare** or **Scaleway Edge Services**) in front of your domain, you route requests to either S3 or the Serverless Container:
*   **Static Reads (S3 / CDN)**: Serving files and reading schedules from S3 takes **0ms cold start**, handles millions of concurrent users effortlessly, and consumes **zero** container CPU.
*   **Dynamic API (Serverless Container)**: Your App Container is only triggered for authentications and schedule modifications. It safely scales to zero when no active editing is happening, resulting in **near-zero hosting costs**.

---

## 📡 Mercure Hub Deployment (Dedicated VM)

Because clients maintain active real-time SSE (Server-Sent Events) connections to listen to schedule updates, they must remain connected continuously.

> ⚠️ **Why not Serverless Containers?** Scaleway Serverless Containers are strictly capped at **80 concurrent connections** per instance. In a larger Barcamp event, this limit is easily exhausted, causing connection drops for attendees.

To support unlimited concurrent connections efficiently, you must deploy the Mercure Hub on a dedicated VM, such as a **Scaleway Stardust Compute Instance** (or larger). A Stardust instance is extremely cost-effective and easily handles thousands of concurrent SSE connections.

*Note: For details on how the application utilizes Mercure without relying on its history features, see [Real-Time Architecture: Mercure Usage](docs/architecture/realtime-updates.md).*

### 1. Provision the Scaleway Instance
1. Go to the Scaleway Console and create a **Compute Instance**.
2. Select **Stardust** (or **GP1-XS** / **DEV1-S** depending on availability) running **Ubuntu 24.04 LTS**.
3. **Crucial:** Enable and assign a public **IPv4 address** to the instance.
4. Ensure your Security Group allows incoming traffic on port **80 (HTTP)** and **443 (HTTPS)**.

### 2. Configure DNS
Create an `A` record (and optionally `AAAA` if IPv6 is enabled) for your custom Mercure domain (e.g., `mercure.yourdomain.com`) pointing to your instance's public IP address.

*Mercure features a built-in Caddy server that automatically provisions and renews Let's Encrypt SSL certificates. It only needs the DNS record to point to the VM.*

### 3. Provision with cloud-init (Zero-SSH Setup)
Instead of manually logging in via SSH, you can completely automate the installation of Docker, configuration of secure unattended upgrades, and the deployment of the Mercure container using `cloud-init`.

When creating your Stardust instance in the Scaleway Console:
1. Scroll down to **Advanced Settings**.
2. Under **User Data**, select **Cloud-init**.
3. Paste the following configuration. 

> 🔐 **Secret Management Best Practice:** You do **not** need to put your real production JWT secrets in this cloud-init template! You can leave the placeholder values as-is. Because our GitHub Actions CI pipeline is configured to redeploy Mercure via SSH, **the first CI run will automatically overwrite these placeholders and securely inject your production secrets** from GitHub Secrets.

```yaml
#cloud-config
package_update: true
package_upgrade: true

# 1. Pre-seed unattended-upgrades to "true" in the debconf database
apt:
  debconf_selections:
    unattended-upgrades: "unattended-upgrades unattended-upgrades/enable_auto_updates boolean true"

# 2. Create unprivileged deployment user
users:
  - default
  - name: mercure-deployer
    gecos: Mercure Deployment Agent
    shell: /bin/bash
    ssh_authorized_keys:
      - no-port-forwarding,no-x11-forwarding,no-agent-forwarding ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... # Replace with your SSH public key

write_files:
  # 3. Configure apt-listchanges to run silently BEFORE the package is installed
  - path: /etc/apt/listchanges.conf
    content: |
      [apt]
      frontend=none
      confirm=0
      save_seen=/var/lib/apt/listchanges.db
      which=both
    owner: root:root
    permissions: '0644'

  # 4. Your custom unattended-upgrades configuration
  - path: /etc/apt/apt.conf.d/50unattended-upgrades
    content: |
      Unattended-Upgrade::Allowed-Origins {
          "${distro_id}:${distro_codename}-security";
          "${distro_id}-security:${distro_codename}-security";
      };
      Unattended-Upgrade::Automatic-Reboot "true";
      Unattended-Upgrade::Automatic-Reboot-Time "04:00";
    owner: root:root
    permissions: '0644'

  # 5. Create restricted sudoers configuration for the deployer user
  - path: /etc/sudoers.d/mercure-deployer
    content: |
      Defaults:mercure-deployer env_keep += "MERCURE_JWT_SECRET MERCURE_DOMAIN MERCURE_CORS_ORIGINS MERCURE_IMAGE"
      mercure-deployer ALL=(ALL) NOPASSWD: /usr/local/bin/redeploy-mercure.sh
    owner: root:root
    permissions: '0440'

  # 6. Create the self-contained deployment script
  - path: /usr/local/bin/redeploy-mercure.sh
    content: |
      #!/bin/bash
      set -euo pipefail
      
      # Ensure variables are set
      MERCURE_DOMAIN="${MERCURE_DOMAIN:-mercure.yourdomain.com}"
      # Docker image to run. Overridable via CI (repo variable MERCURE_IMAGE);
      # the default is pinned so deploys never pick up breaking major releases.
      MERCURE_IMAGE="${MERCURE_IMAGE:-dunglas/mercure:v0.24.2}"
      if [ -z "${MERCURE_CORS_ORIGINS:-}" ]; then
        echo "❌ Error: MERCURE_CORS_ORIGINS variable is not set! This variable is required for security."
        exit 1
      fi
      if [ -z "${MERCURE_JWT_SECRET:-}" ]; then
        echo "❌ Error: MERCURE_JWT_SECRET variable is not set!"
        exit 1
      fi
      
      echo "Pulling Mercure Docker image ($MERCURE_IMAGE)..."
      docker pull "$MERCURE_IMAGE"
      
      echo "Stopping existing Mercure container..."
      docker stop mercure || true
      docker rm mercure || true
      
      echo "Starting new Mercure container..."
      docker run -d \
        --name mercure \
        --restart unless-stopped \
        -p 80:80 \
        -p 443:443 \
        -e SERVER_NAME="$MERCURE_DOMAIN" \
        -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_JWT_SECRET" \
        -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_JWT_SECRET" \
        -e MERCURE_EXTRA_DIRECTIVES="$(printf 'cors_origins %s\ntransport local' "$MERCURE_CORS_ORIGINS")" \
        -v mercure_data:/data \
        -v mercure_config:/config \
        "$MERCURE_IMAGE"
    owner: root:root
    permissions: '0755'

packages:
  - unattended-upgrades
  - apt-listchanges
  - docker.io

runcmd:
  # 7. Apply the pre-seeded auto-update decision non-interactively
  - dpkg-reconfigure -f noninteractive unattended-upgrades

  # 8. Enable and start Docker
  - systemctl enable --now docker
```

> 💡 **Why is CORS restricted?** We explicitly set `cors_origins https://schedule.yourdomain.com` (replace with your actual app domain) so that external, malicious websites cannot connect to your Mercure Hub and hijack your schedule update events.

Once the instance boots up (it may take 2–3 minutes for the `cloud-init` script to finish all updates, install Docker, and start the container), copy your domain URL (e.g., `https://mercure.yourdomain.com/.well-known/mercure`) and use it in your App Container variables (`MERCURE_URL` and `MERCURE_PUBLIC_URL`).

---

## 🪣 Scaleway Object Storage (S3) Setup

You will create a single Scaleway Object Storage bucket that will serve three purposes:
1.  **Frontend Website**: Hosts the compiled Vue 3 SPA files (`index.html`, `assets/`, etc.).
2.  **Uploads**: Stores uploaded PDFs and images (`uploads/*`).
3.  **Schedules**: Stores the dynamically compiled schedule JSON files (`schedules/*`).

### 1. Create your Bucket
Create an Object Storage bucket in your target region (e.g., `fr-par`).

### 2. Enable Bucket Website Hosting
1. Go to your bucket's **Bucket Settings** in the Scaleway Console.
2. Click **Enable bucket website**.
3. Set the **Index Document** to `index.html`.
4. Save the configuration. This yields your static URL, e.g., `http://<your-bucket-name>.s3-website.fr-par.scw.cloud`.

### 3. Configure CORS Policies
While standard users visiting the SPA directly on `schedule.example.com` do not trigger S3-level CORS, **CORS is still required for the embedded web component**. 

When external partner websites embed your widget (`embed.js` loaded from `schedule.example.com`), their browsers will perform cross-origin fetches to load sub-resources (such as Web Fonts, JSON translations, or stylesheets) from your domain. To allow this, S3 must return CORS headers. Because Scaleway Edge Services forwards headers from the origin by default, configuring CORS at the S3 bucket level is the most robust way to handle this:
```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedOrigins": ["*"],
    "MaxAgeSeconds": 3600
  }
]
```

### 4. Configure Public Bucket Policy
For static serving, files under `uploads/`, `schedules/`, and frontend assets must be publicly readable. Apply this **Bucket Policy** under your bucket settings:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadGetObject",
      "Effect": "Allow",
      "Principal": "*",
      "Action": ["s3:GetObject"],
      "Resource": [
        "arn:aws:s3:::<your-bucket-name>/*"
      ]
    }
  ]
}
```

### 5. Caching Strategy
During deployment, the CI/CD pipeline applies targeted `Cache-Control` headers for different static asset types to balance performance and freshness:
*   **Hashed Assets (`/assets/*`)**: Configured with a long-term, immutable cache policy (`Cache-Control: public, max-age=31536000, immutable`). These files contain unique content hashes in their filenames and can be cached indefinitely by browsers and CDNs.
*   **Entry Points (`index.html`, `embed.js`)**: Configured with a short edge caching policy (`Cache-Control: public, max-age=0, s-maxage=300`). This ensures that user browsers always check with the edge CDN (`max-age=0`), but the edge CDN can cache the files for up to 5 minutes (`s-maxage=300`) to significantly reduce backend and S3 read requests during high-traffic spikes, while still propagating updates relatively quickly.

---

## 🚀 Step-by-Step Serverless Deployment

### Step 1: Build the Vue Frontend and Upload to S3
1. Compile your frontend locally or in your CI:
   ```bash
   cd frontend
   npm ci
   npm run build
   ```
2. Upload the entire contents of the `frontend/dist` directory to the **root** of your S3 bucket.

### Step 2: Run Database Migrations (Scaleway Serverless Job)
Because the App Container is designed to scale to zero and spin up multiple concurrent instances during high traffic, running database migrations on startup (**cold start**) is extremely risky. Multiple containers starting up at the same time would execute DDL statements concurrently, leading to lock contention or schema corruption.

Therefore:
1.  **Disable Migrations on Boot**: Set `MIGRATE_ON_BOOT=false` in the environment variables of your dynamic App Container (documented below).
2.  **Run Migrations via Serverless Job**: Run migrations as a one-off discrete task once the container image has been built, but before the App Container serves traffic.

You can trigger this migration job using the Scaleway CLI or via Scaleway Serverless Jobs:
```bash
scw container job run \
    --region "fr-par" \
    --namespace-name "barcamp-schedule" \
    --name "migrate" \
    --image "rg.fr-par.scw.cloud/<your-registry-namespace>:latest" \
    --cpu-limit 280 \
    --memory-limit 512 \
    --command "php /app/vendor/bin/doctrine-migrations migrate --db-configuration=/app/migrations-db.php --no-interaction" \
    --env "DB_DRIVER=pdo_pgsql" \
    --env "DB_HOST=your-db-host" \
    --env "DB_PORT=5432" \
    --env "DB_NAME=barcamp" \
    --env "DB_USER=barcamp" \
    --env "DB_PASSWORD=your-db-password"
```

This ensures your database schema is safely migrated prior to your web traffic hitting the newly updated application container.

### Step 3: Deploy the App Container
Since the container no longer serves the frontend files, the container acts solely as the FrankenPHP API.
1. Deploy your backend container using the image built by your CI/CD workflow (`rg.fr-par.scw.cloud/<your-registry-namespace>:latest`).
2. Set the container scaling:
   *   **Min instances**: `0` (This is critical: since read-only clients fetch files and schedules directly from S3, your container scales completely to zero when idle, resulting in **zero** cost!).
   *   **Max instances**: `3` (Ensures scalability during busy planning/editing sessions).
   *   **Port**: `8000`.

### Step 4: Configure Edge-Level DNS & Routing (e.g., Cloudflare)
To serve your application under a single custom domain (e.g., `schedule.yourdomain.com`) and route traffic with **zero container wakeups**, configure an edge routing proxy.

#### DNS Setup
Create a CNAME record on your custom domain pointing to your Scaleway S3 Bucket Website URL (`<bucket-name>.s3-website.fr-par.scw.cloud`).

#### Edge Rules / Cloudflare Page Rules
Configure the following routing rules on your edge network:

1.  **Rule 1: Upload Reads (Served statically from S3)**
    *   **Pattern**: `schedule.yourdomain.com/api/uploads/*`
    *   **Behavior**: Rewrite/Proxy path to `https://s3.fr-par.scw.cloud/<your-bucket-name>/uploads/$1`
2.  **Rule 2: Schedule Reads (Served statically from S3)**
    *   **Pattern**: `schedule.yourdomain.com/api/schedules/([a-f0-9]+)$`
    *   **Behavior**: Rewrite/Proxy path to `https://s3.fr-par.scw.cloud/<your-bucket-name>/schedules/$1.json`
3.  **Rule 3: Live subscriptions & Auth API (Routed to Serverless Container)**
    *   **Pattern**: `schedule.yourdomain.com/api/*`
    *   **Behavior**: Forward request to the **Scaleway Serverless Container URL** (e.g., `https://<your-app-container-id>.functions.fnc.fr-par.scw.cloud/api/*`).
4.  **Rule 4: Vue SPA (Served statically from S3 Website)**
    *   **Pattern**: `schedule.yourdomain.com/*`
    *   **Behavior**: Forward requests directly to the **Scaleway S3 Website Hosting Endpoint**.

This ensures that **only** dynamic POST/PATCH/DELETE mutations and real-time subscription handshakes hit the container, while all reading of schedule details, attachments, and Vue files is offloaded directly to Scaleway Object Storage.

---

## Environment Variables (App Container)

Inject these environment variables into your **App Container**:

| Variable             | Description                              | Example / Value                                            |
|----------------------|------------------------------------------|------------------------------------------------------------|
| `APP_ENV`            | Application environment                  | `production`                                               |
| `APP_DEBUG`          | Debug mode                               | `false`                                                    |
| `MIGRATE_ON_BOOT`    | Run migrations on container boot         | `false` (Must be false in prod to avoid lock contention)   |
| `DB_DRIVER`          | Database driver                          | `pdo_pgsql`                                                |
| `DB_HOST`            | Database host                            | Your Scaleway PG endpoint                                  |
| `DB_PORT`            | Database port                            | `5432`                                                     |
| `DB_NAME`            | Database name                            | `barcamp`                                                  |
| `DB_USER`            | Database user                            | `barcamp`                                                  |
| `DB_PASSWORD`        | Database password                        | Use **Scaleway Secret**                                    |
| `DB_SSLMODE`         | SSL mode for PostgreSQL connection       | `require` (Required for Scaleway Serverless SQL DB to send SNI) |
| `ADMIN_EMAIL`        | Initial admin user email                 | `admin@example.com`                                        |
| `JWT_SECRET`         | Secret for signing JWTs (32+ characters) | Use **Scaleway Secret**                                    |
| `MERCURE_JWT_SECRET` | Secret for Mercure Hub JWTs              | Use **Scaleway Secret**                                    |
| `MERCURE_URL`        | URL of the Mercure Hub (publishing)      | `https://<your-mercure-container-url>/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | Public Mercure Hub URL (clients)         | `https://<your-mercure-container-url>/.well-known/mercure` |
| `APP_URL`            | Public URL of the application            | `https://schedule.yourdomain.com`                          |
| `API_CORS_ORIGIN`     | Allowed domain(s) for backend CORS requests. Supports multiple space-separated **domains** (Do NOT include `https://` or `http://` as the Caddyfile already prefixes this scheme. e.g. `schedule.yourdomain.com yourdomain.com`) | `schedule.yourdomain.com`                                  |
| `S3_ENDPOINT`        | Scaleway S3 Endpoint                     | `https://s3.fr-par.scw.cloud`                                |
| `S3_REGION`          | Scaleway S3 Region                       | `fr-par`                                                     |
| `S3_ACCESS_KEY`      | Scaleway API Access Key                  | Use **Scaleway Secret** (Same as `SCW_ACCESS_KEY`)         |
| `S3_SECRET_KEY`      | Scaleway API Secret Key                  | Use **Scaleway Secret** (Same as `SCW_SECRET_KEY`)         |
| `S3_BUCKET`          | Scaleway Object Storage Bucket Name      | `your-bucket-name`                                         |
| `PHP_WORKER_COUNT`   | Number of persistent FrankenPHP workers. Defaults to `2`. Set to lower values on resource-constrained containers. | `2`                                                        |
| `PHP_MEMORY_LIMIT`   | Maximum memory allocation limit for PHP requests. Defaults to `256M`. | `128M`                                                     |
| `PHP_OPCACHE_MEMORY_CONSUMPTION` | Memory allocated to the PHP OPcache engine (in MB). Defaults to `64`. | `48`                                                       |

---

## ⚙️ Performance Tuning (Serverless Constraints)

Serverless environments often expose the host's actual CPU core count to the container, which can cause FrankenPHP to over-allocate workers by default. For stable performance and to avoid **Out of Memory (OOM)** crashes, you should tune your environment variables based on your container's allocation.

### Profile 1: 500 mvcpu (0.5 vCPU) / 512 MB RAM (Standard Serverless)
A single 0.5 vCPU container cannot handle high throughput. To keep memory footprints low and avoid heavy CPU context switching, use the following configuration:

*   **`PHP_WORKER_COUNT`**: `1` or `2` (Never set higher than 2. Setting it to 1 is the most predictable for CPU efficiency; 2 allows one worker to continue running while the other is waiting on database I/O).
*   **`PHP_MEMORY_LIMIT`**: `128M` (Forces heavy queries/leaks to recycle the worker early, saving the host container from OOMing).
*   **`PHP_OPCACHE_MEMORY_CONSUMPTION`**: `48` (Allocates 48MB for precompiled code, leaving plenty of system heap headroom).
*   **Scaling Configuration:**
    *   **Scale-Up Trigger (CPU-based):** Set your platform to scale up at **70% CPU**.
    *   **Scale-Up Trigger (Request-based):** Trigger scale-up at **8–10 req/s** per container. A 0.5 vCPU container will max out at about 10 req/s for dynamic database operations.

### Profile 2: 1000 mvcpu (1.0 vCPU) / 1024 MB (1 GB) RAM (Recommended Production)
This is the recommended baseline for high-traffic events. It provides safe headroom for sudden spikes or heavy uploads:

*   **`PHP_WORKER_COUNT`**: `4` (Allows multiple concurrent requests to be handled efficiently).
*   **`PHP_MEMORY_LIMIT`**: `256M`
*   **`PHP_OPCACHE_MEMORY_CONSUMPTION`**: `128` (Generous precompile memory for optimal speed).
*   **Scaling Configuration:**
    *   **Scale-Up Trigger (CPU-based):** Set platform to scale up at **70% CPU**.
    *   **Scale-Up Trigger (Request-based):** Trigger scale-up at **25–30 req/s** per container.

---

## 🔐 IAM Policy Hardening (Principle of Least Privilege)

When setting up your Scaleway API Keys (`SCW_ACCESS_KEY` and `SCW_SECRET_KEY`) for GitHub Actions, ensure that the API key does **not** use an owner or highly privileged role. Instead, create a dedicated IAM Application specifically for your CI/CD workflow and attach a custom policy with only the minimum required permissions:

### Recommended Custom IAM Policy
Attach a policy with the following restricted API permissions to your CI/CD application:

1. **Object Storage (S3)**
   - `ObjectStorageWrite` / `ObjectStorageBucketRead` (only for your specific bucket)
2. **Container Registry**
   - `ContainerRegistryWrite` / `ContainerRegistryRead` (only for your specific registry)
3. **Serverless Containers**
   - `ServerlessContainersFullAccess`
4. **Serverless Jobs**
   - `ServerlessJobsFullAccess`
5. **Edge Services (CDN)**
   - `EdgeServicesCachePurge`
