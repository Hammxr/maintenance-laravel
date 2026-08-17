# Deployment Plan — Google Cloud VM + Docker Compose

Target: a single Compute Engine VM running the whole stack (app, MySQL, Redis, reverse proxy)
via Docker Compose. No managed GCP database — MySQL runs as a container on the same VM.

---

## Target architecture

```
                    Internet
                       │
                  :80 / :443
                       │
              ┌────────▼────────┐
              │  caddy          │  TLS termination (Let's Encrypt), reverse proxy
              └────────┬────────┘
                       │ :8000 (internal network only)
              ┌────────▼────────┐
              │  app            │  Octane/RoadRunner + Horizon + scheduler
              │  (CONTAINER_    │  (one container, three supervisord programs)
              │   MODE=http)    │
              └───┬─────────┬───┘
                  │         │
        ┌─────────▼──┐   ┌──▼──────────┐
        │  mysql:8.0 │   │ redis:7     │   both internal-only, named volumes
        └────────────┘   └─────────────┘
```

The `app` container runs three processes under supervisord, controlled by env vars
(see `.docker/octane/RoadRunner/supervisord.roadrunner.conf`):

| Program     | Enabled by          | Purpose                                              |
|-------------|---------------------|------------------------------------------------------|
| `octane`    | always              | HTTP server on :8000                                 |
| `horizon`   | `WITH_HORIZON=true` | Queue workers — **required**, queue driver is redis   |
| `scheduler` | `WITH_SCHEDULER=true` | supercronic → `schedule:run` — **required** for preventive-maintenance reminders |
| `reverb`    | `WITH_REVERB=true`  | Websockets — leave off unless broadcasting is used    |

Running Horizon and the scheduler inside the `app` container keeps a single-VM deploy
simple. The separate `horizon` / `worker` compose services exist for multi-node setups;
you don't need them here.

---

## Phase 0 — Pre-flight fixes (do these locally, before touching GCP)

These are blockers found in the current repo state. All are small.

### 0.1 Commit the outstanding work

The working tree has ~55 modified files and ~20 new ones on `main` — the "Republic Lifestyle"
rebrand, the hour-counter page, the change-log report, `MaintenanceSchedulingService`, and
5 unapplied migrations. Deployment builds from git, so anything uncommitted will not ship.

```bash
git add -A && git commit -m "Rebrand, hour counter, change log report, maintenance scheduling"
```

### 0.2 Build frontend assets and commit them

**The Dockerfile has no Node stage.** There is no `npm install` / `npm run build` anywhere in
the image build — it copies `public/build` straight from the repo, and `public/build` is not
gitignored. If you skip this, the deployed app loads with no CSS.

```bash
npm ci
npm run build
git add public/build && git commit -m "Rebuild frontend assets"
```

Stale asset files (`app-BlDURH-O.css`, `theme-ZPVDWGkr.css`) are already deleted in your working
tree — the `git add -A` in 0.1 handles removing them from the manifest.

### 0.3 Fix the container healthcheck

`docker-compose.yml:35` runs `test: ['CMD', 'healthcheck']`, but the Dockerfile never puts
`.docker/healthcheck` on `PATH` — it only lands at `/var/www/html/.docker/healthcheck` via the
bulk `COPY . .`. The container will permanently report `unhealthy`, which matters once Caddy
uses `depends_on: condition: service_healthy`.

Add to the Dockerfile, alongside the other `.docker` copies (around line 164):

```dockerfile
COPY --chown=${USER}:${USER} .docker/healthcheck /usr/local/bin/healthcheck
```

and extend the existing `chmod +x` line to cover it:

```dockerfile
RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck && \
    cat .docker/utilities.sh >> ~/.bashrc
```

### 0.4 Trust the reverse proxy

`bootstrap/app.php` does not configure trusted proxies. Behind Caddy, Laravel will see the
request as plain HTTP and generate `http://` URLs — which breaks Livewire/Filament asset loading
and login redirects under HTTPS.

In `bootstrap/app.php`, inside `->withMiddleware(...)`:

```php
$middleware->trustProxies(at: '*');
```

`at: '*'` is correct here — Caddy is the only thing that can reach the app container, since
port 8000 is never published to the host.

### 0.5 Decide on domain + DNS

You need a hostname before Caddy can issue a certificate. Have the domain ready and be able to
edit its A record.

---

## Phase 1 — Provision GCP infrastructure

Install and authenticate the `gcloud` CLI first, then:

```bash
gcloud config set project YOUR_PROJECT_ID
gcloud services enable compute.googleapis.com
```

**Pick a region.** `africa-south1` (Johannesburg) is the latency win if your users are in South
Africa. Otherwise choose whatever is closest to them.

### 1.1 Reserve a static IP

Do this before creating the VM — an ephemeral IP changes on stop/start and will break DNS and
your TLS certificate.

```bash
gcloud compute addresses create maintenance-ip --region=africa-south1
gcloud compute addresses describe maintenance-ip --region=africa-south1 --format='value(address)'
```

### 1.2 Create the VM

```bash
gcloud compute instances create maintenance-app \
  --zone=africa-south1-a \
  --machine-type=e2-standard-2 \
  --image-family=debian-12 \
  --image-project=debian-cloud \
  --boot-disk-size=50GB \
  --boot-disk-type=pd-balanced \
  --address=maintenance-ip \
  --tags=http-server,https-server
```

Sizing: `e2-standard-2` (2 vCPU / 8 GB) is the right call — Octane workers, MySQL's InnoDB
buffer pool, Redis and Horizon all share this box. `e2-medium` (4 GB) will run it but leaves
little headroom; if you use it, add 2 GB of swap. 50 GB of disk covers the OS, Docker images,
the database and uploaded documents with room to grow.

### 1.3 Firewall

```bash
gcloud compute firewall-rules create allow-http-https \
  --allow=tcp:80,tcp:443 \
  --target-tags=http-server,https-server \
  --source-ranges=0.0.0.0/0
```

Do **not** open 3306 or 6379. SSH via `gcloud compute ssh` (which uses IAP/OS Login) rather than
opening 22 to the world.

### 1.4 DNS

Point an A record for your domain at the static IP from 1.1. Verify propagation before Phase 4 —
Let's Encrypt will fail otherwise.

---

## Phase 2 — Bootstrap the VM

```bash
gcloud compute ssh maintenance-app --zone=africa-south1-a
```

### 2.1 Install Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```

Log out and back in for the group change to apply.

### 2.2 Cap Docker log growth

Container logs go to stdout and will fill the disk unchecked. Create `/etc/docker/daemon.json`:

```json
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
```

Then `sudo systemctl restart docker`.

### 2.3 Create the deploy directory

```bash
sudo mkdir -p /opt/maintenance && sudo chown $USER:$USER /opt/maintenance
```

---

## Phase 3 — Deploy the application

### 3.1 Get the code onto the VM

Generate a deploy key on the VM, add it to the GitHub repo as a read-only deploy key, then:

```bash
git clone git@github.com:YOUR_ORG/maintenance-laravel.git /opt/maintenance
```

### 3.2 Write the production `.env`

Copy `.env.example` to `.env` and change these. **`.dockerignore` excludes `.env`**, so it never
enters the image — Compose injects it at runtime via `env_file`, and those process env vars take
precedence over the placeholder `.env` baked into the image.

| Key | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **Critical** — `true` leaks env vars and stack traces publicly |
| `APP_URL` | `https://your-domain` | Asset and redirect URL generation |
| `APP_KEY` | *(see 3.3)* | |
| `LOG_LEVEL` | `warning` | `debug` is very noisy in production |
| `DB_HOST` | `mysql` | Compose service name |
| `DB_DATABASE` | `liberu_maintenance` | |
| `DB_USERNAME` | `liberu` | Not `root` |
| `DB_PASSWORD` | *strong random* | Also set `DB_ROOT_PASSWORD` |
| `REDIS_HOST` | `redis` | Compose service name |
| `CACHE_DRIVER` | `redis` | Currently `file` — won't survive Octane restarts cleanly |
| `SESSION_DRIVER` | `redis` | Currently `file`. File sessions live **inside the container**, so every restart or redeploy logs everyone out — and an expired session makes Livewire receive the login page instead of JSON, which surfaces as a blank overlay rather than a "please log in" message |
| `SESSION_LIFETIME` | `480` | Default 120 minutes is too short for a shop-floor tool: a technician who opens a list, goes to the floor for two hours and comes back to hit Save gets the blank-overlay failure above |
| `QUEUE_CONNECTION` | `redis` | Already correct |
| `OCTANE_SERVER` | `roadrunner` | **Must not be `swoole`** — the image only installs RoadRunner and only ships the RoadRunner supervisord config |
| `WITH_HORIZON` | `true` | Queues won't process otherwise |
| `WITH_SCHEDULER` | `true` | Maintenance reminders won't fire otherwise |
| `WITH_REVERB` | `false` | Unless you need websockets |
| `BROADCAST_DRIVER` | `log` | Set to `reverb` only if you enable Reverb |
| `MAIL_*` | real SMTP creds | Currently points at `mailpit`; notifications will silently fail |

Note `.env.example` ships `OCTANE_SERVER=swoole` — this is the single most likely thing to make
the first boot fail. Your current local `.env` already has `roadrunner`; carry that forward.

Lock the file down: `chmod 600 .env`

### 3.3 Generate the app key

```bash
docker compose run --rm --entrypoint php app artisan key:generate --show
```

Paste the output into `.env` as `APP_KEY`.

### 3.4 Add a production compose override

Create `/opt/maintenance/docker-compose.prod.yml`. This removes the published database ports
(the base file exposes 3306 and 6379 to the host) and adds Caddy:

```yaml
services:
  app:
    ports: []          # only Caddy reaches the app
    environment:
      CONTAINER_MODE: http
      WITH_HORIZON: 'true'
      WITH_SCHEDULER: 'true'
      RUNNING_MIGRATIONS_AND_SEEDERS: 'false'

  mysql:
    ports: []
    command: --default-authentication-plugin=caching_sha2_password

  redis:
    ports: []

  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - '80:80'
      - '443:443'
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      app:
        condition: service_healthy
    networks:
      - appnet

volumes:
  caddy_data:
  caddy_config:
```

Always deploy with both files:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### 3.5 Initialise the database — do NOT use the seeder flag

`RUNNING_MIGRATIONS_AND_SEEDERS=true` runs `migrate --seed`, and `DatabaseSeeder` calls
`CompanySeeder`, `EquipmentSeeder`, `WorkOrderSeeder`, `ChecklistSeeder`,
`MaintenanceScheduleSeeder` and `InventorySeeder` — **demo data you do not want in production**.
There is no flag to run migrations without seeding, so do it by hand.

Bring up the stack, then run only the seeders the app genuinely needs to function:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan db:seed --class=PermissionsSeeder --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan db:seed --class=RolesSeeder --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan db:seed --class=TechnicianRoleSeeder --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan db:seed --class=MenuSeeder --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan db:seed --class=TeamSeeder --force
```

Permissions, roles and menus are structural — Filament Shield and the navigation depend on them.
`TeamSeeder` must run before any user is created, because Jetstream teams are the tenant.

### 3.6 Create your admin user

`UserSeeder` hardcodes `admin@example.com` and prints a random password to stdout. For production,
create your own instead:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan tinker
```

```php
$u = App\Models\User::create([
    'name' => 'Your Name',
    'email' => 'you@your-domain',
    'password' => Hash::make('a-strong-password'),
    'email_verified_at' => now(),
]);
App\Models\Team::first()->users()->syncWithoutDetaching([$u->id]);
$u->assignRole('super_admin');
```

---

## Phase 4 — TLS and reverse proxy

Create `/opt/maintenance/Caddyfile`:

```
your-domain.com {
    reverse_proxy app:8000
    encode gzip zstd
}
```

Caddy obtains and renews a Let's Encrypt certificate automatically on first request. Requirements:
DNS must already resolve to the VM, and ports 80 and 443 must be open (Phase 1.3).

If you enable Reverb later, add a websocket route:

```
    reverse_proxy /app/* app:8080
```

Verify:

```bash
curl -I https://your-domain.com/up
```

`/up` is Laravel's built-in health endpoint, registered in `bootstrap/app.php`.

---

## Phase 5 — Backups and operations

### 5.1 Database backups to Cloud Storage

This is the main thing you give up by not using Cloud SQL, so don't skip it.

```bash
gcloud storage buckets create gs://YOUR-BUCKET-maintenance-backups --location=africa-south1
```

Give the VM's service account `roles/storage.objectCreator` on that bucket. Then add
`/opt/maintenance/backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /opt/maintenance
STAMP=$(date +%F-%H%M)
docker compose exec -T mysql mysqldump \
  -u root -p"${DB_ROOT_PASSWORD}" --single-transaction --routines \
  liberu_maintenance | gzip > "/tmp/db-${STAMP}.sql.gz"
gcloud storage cp "/tmp/db-${STAMP}.sql.gz" "gs://YOUR-BUCKET-maintenance-backups/"
rm -f "/tmp/db-${STAMP}.sql.gz"
```

Schedule it via host cron (`0 2 * * *`) and set a bucket lifecycle rule to expire objects after
30 days. **Test a restore before you rely on it.**

Also back up the `storage` volume — uploaded documents and manuals live there, not in the database.

### 5.2 Monitoring

```bash
curl -sSO https://dl.google.com/cloudagents/add-google-cloud-ops-agent-repo.sh
sudo bash add-google-cloud-ops-agent-repo.sh --also-install
```

Add a GCP uptime check against `https://your-domain.com/up`, and alerts on disk >80% and
memory >90%.

### 5.3 Automatic security updates

```bash
sudo apt install -y unattended-upgrades
```

---

## Phase 6 — Update procedure

```bash
cd /opt/maintenance
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force
```

Remember Phase 0.2 — run `npm run build` and commit `public/build` on your machine before any
deploy that touches CSS or JS, because the image build will not do it for you.

`start-container` runs `optimize:clear`, `event:cache`, `config:cache` and `route:cache` on every
boot, so caches refresh themselves on restart. Env changes require a container restart to take
effect, not just a file edit.

**Take a backup before every migration.**

---

## Rollback

```bash
cd /opt/maintenance
git checkout <previous-good-sha>
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Schema rollback is a restore from the pre-migration backup — `migrate:rollback` is unreliable
against a production dataset. Consider `gcloud compute disks snapshot` on the boot disk before
significant releases for a whole-VM restore point.

---

## Cost estimate (monthly, approximate)

| Item | Cost |
|---|---|
| `e2-standard-2` VM | ~$50 |
| 50 GB pd-balanced | ~$5 |
| Static IP (in use) | ~$3 |
| GCS backups (a few GB) | <$1 |
| **Total** | **~$60** |

Roughly $35 with `e2-medium` if you want to trim. A committed-use discount cuts 30–40% if you
commit to a year.

---

## Known limitations of this setup

Worth naming explicitly, since they're the trade for the simplicity:

- **Single point of failure.** VM or disk loss means downtime; recovery is restore-from-backup.
- **Deploys have downtime.** A few seconds while containers restart. Blue/green would need a
  second app container plus Caddy load balancing.
- **You own MySQL.** Tuning, patching, backup verification and disk headroom are all yours.
- **Migration path.** If any of the above starts to hurt, moving MySQL to Cloud SQL is a
  `DB_HOST` change plus a dump/restore, and the `k8s/` manifests already in the repo are a
  starting point for GKE. Neither requires rearchitecting the app.
