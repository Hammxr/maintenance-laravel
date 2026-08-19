# Migrating the deployment to another GCP account

Moves the running deployment from GCP project `republic-pms` (instance `maintenance-app`,
zone `africa-south1-a`, IP `34.35.38.52`) onto a new VM in a different Google Cloud
account, and switches TLS from Caddy's internal CA to a real certificate for a domain.

The companion document [DEPLOYMENT_GCP_VM.md](DEPLOYMENT_GCP_VM.md) covers a first-time
build in more detail. This one only covers what differs when moving an existing install.

## What actually moves

Almost nothing. The whole stack is declarative — `Dockerfile`, `docker-compose.yml`,
`.docker/*` — and lives in GitHub, so the new VM rebuilds images from source rather than
copying anything.

| Item | Action |
|---|---|
| Application code | `git clone` on the new VM |
| Docker images | Rebuilt with `docker compose build` |
| `/opt/maintenance/.env` | Recreated, **not** copied verbatim — see below |
| Database | Recreated empty: `migrate` plus the structural seeders only |
| `storage` volume (uploads) | Only needs moving if documents have been uploaded |
| Caddy `caddy_data` volume | Discarded — a new Let's Encrypt cert is issued |
| Firewall rules | Recreated (80/443 only) |
| Static IP | New address reserved in the new project |

**Do not copy `.env` verbatim.** It is the right moment to leave behind two things the
original deployment inherited from `.env.example`: the compose-default database
credentials (`secret`), and an `APP_KEY` that has now been shared into a chat transcript.
Generate fresh values for both.

## Before starting

Have ready:

1. The **hostname**, and the ability to edit its DNS A record.
2. The **target project ID** in the other account.
3. **Authentication** to that account — either `gcloud auth login` as an identity with
   rights there, or an IAM grant on the existing identity. Keep configurations separate:

   ```bash
   gcloud config configurations create newaccount
   gcloud auth login
   gcloud config set project TARGET_PROJECT_ID
   gcloud config set compute/zone africa-south1-a
   # switch back at any time with: gcloud config configurations activate default
   ```

Run the new VM as its **own instance**, not alongside the other programme already in that
account. Two applications both wanting ports 80 and 443 on one host forces a shared
reverse proxy and entangles their restarts and certificates.

## 1. Reserve an address and point DNS at it

```bash
gcloud compute addresses create maintenance-ip --region=africa-south1
gcloud compute addresses describe maintenance-ip --region=africa-south1 --format='value(address)'
```

Create an **A record** for the hostname pointing at that address, then confirm it has
propagated before going near Caddy:

```bash
dig +short YOUR_HOSTNAME
```

Caddy proves domain ownership over port 80. If DNS is wrong or the firewall is closed when
it first starts, certificate issuance fails and it falls back to serving nothing useful.
Get this green first.

## 2. Create the VM and open only 80/443

```bash
gcloud compute instances create maintenance-app \
  --zone=africa-south1-a \
  --machine-type=e2-standard-2 \
  --image-family=debian-12 \
  --image-project=debian-cloud \
  --boot-disk-size=50GB \
  --address=maintenance-ip

gcloud compute firewall-rules create allow-http-https \
  --allow=tcp:80,tcp:443 \
  --source-ranges=0.0.0.0/0 \
  --description="Public HTTP/HTTPS to Caddy"
```

**Port 22 is deliberately not opened.** `gcloud compute ssh` reaches the instance through
IAP/OS Login without an inbound SSH rule, and that is how the old deployment is
administered. Do not add one.

Do not publish 3306, 6379 or 8000 either — `docker-compose.yml` already binds those to
`127.0.0.1`, and Caddy is the only public entrypoint.

## 3. Install Docker, clone the repo

```bash
gcloud compute ssh maintenance-app --zone=africa-south1-a
```

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo git clone https://github.com/Hammxr/maintenance-laravel.git /opt/maintenance
sudo chown -R "$USER:$USER" /opt/maintenance
```

Note the ownership line. On the original VM the checkout is owned by a different user than
the one `gcloud compute ssh` creates, so every git command there needs
`sudo -u <owner> git -c safe.directory=/opt/maintenance ...`. Setting ownership now avoids
inheriting that annoyance.

## 4. Write the .env

Start from `.env.example` and set at least:

```ini
APP_NAME="Republic Lifestyle"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_HOSTNAME

APP_KEY=            # fill from: openssl rand -base64 32, prefixed with "base64:"

DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=liberu_maintenance
DB_USERNAME=liberu
DB_PASSWORD=        # generate; do not reuse "secret"
DB_ROOT_PASSWORD=   # generate

REDIS_HOST=redis
OCTANE_SERVER=roadrunner

CADDY_SITE_ADDRESS=YOUR_HOSTNAME
CADDY_EMAIL=you@yourcompany.example      # Let's Encrypt expiry notices

RUNNING_MIGRATIONS_AND_SEEDERS=false
```

Generate the key:

```bash
KEY="base64:$(openssl rand -base64 32)"
sudo sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" /opt/maintenance/.env
```

`APP_KEY` must be set before the app starts. An empty one throws
`MissingAppKeyException` inside every RoadRunner worker, which surfaces only as a bare
HTTP 500 with nothing written to `laravel.log` — see
[the Octane notes](#if-every-request-returns-500) below.

**Leave `RUNNING_MIGRATIONS_AND_SEEDERS` false.** When true, `start-container` runs
`migrate --isolated --seed --force`, and `DatabaseSeeder` calls *every* seeder including
the demo ones — `EquipmentSeeder`, `WorkOrderSeeder`, `InventorySeeder` and friends. That
fills a production system with fictional records you then have to identify and delete.
Section 6 seeds deliberately instead.

## 5. Switch Caddy to a real certificate

`.docker/Caddyfile` ships configured for a bare IP, which no public CA will sign for.
With a hostname, delete the `tls internal` line inside the site block:

```diff
 {$CADDY_SITE_ADDRESS::443} {
-	tls internal
-
 	encode zstd gzip
```

Caddy then obtains and renews a Let's Encrypt certificate automatically, and the
click-through browser warning disappears.

Leave the `default_sni` global option in place. It is harmless once a hostname is in use —
browsers send SNI for hostnames — and it is what makes the bare-IP fallback work at all.

## 6. Build, migrate, seed structurally

```bash
cd /opt/maintenance
sudo docker compose build app
sudo docker compose up -d mysql redis
sudo docker compose run --rm app php artisan migrate --force
```

Then seed **only** the structural seeders, in this order:

```bash
for s in PermissionsSeeder RolesSeeder TechnicianRoleSeeder MenuSeeder TeamSeeder UserSeeder; do
  sudo docker compose run --rm app php artisan db:seed --class="$s" --force
done
```

Order matters: `UserSeeder` calls `Team::firstOrFail()` and fails if `TeamSeeder` has not
run, and `RolesSeeder` syncs every existing permission onto `super_admin`, so
`PermissionsSeeder` must precede it.

`UserSeeder` prints a randomly generated password for `admin@example.com` to stdout
**once**. Capture it. If it scrolls past, reset it:

```bash
sudo docker compose run --rm app php artisan tinker --execute='
  $u = App\Models\User::first();
  $u->password = Illuminate\Support\Facades\Hash::make("NEW_PASSWORD_HERE");
  $u->save();
  echo "ok";'
```

Bring the rest up:

```bash
sudo docker compose up -d
```

## 7. Verify

```bash
sudo docker compose ps                  # all healthy
curl -s -o /dev/null -w '%{http_code}\n' https://YOUR_HOSTNAME/up          # 200
curl -s -o /dev/null -w '%{http_code}\n' https://YOUR_HOSTNAME/app/login   # 200
curl -sI http://YOUR_HOSTNAME | head -1                                    # 308 to https
```

No `-k` flag this time — if `curl` validates the certificate without it, Let's Encrypt
issuance succeeded.

Confirm the app agrees:

```bash
sudo docker compose exec app php artisan about | grep -Ei 'Environment|Debug|Views|Config'
```

Expect `production`, Debug `OFF`, and Config/Routes/Events/Views all `CACHED`.

Then log in and check permissions resolved — an immediate 403 after a successful login
means the permissions table is empty. See below.

## 8. Decommission the old VM

Only after the new deployment has served real traffic for a day or two:

```bash
gcloud config configurations activate default
gcloud compute instances delete maintenance-app --zone=africa-south1-a
gcloud compute addresses delete maintenance-ip --region=africa-south1
gcloud compute firewall-rules delete allow-http-https
```

Take a disk snapshot first if you want a rollback path.

## Traps this deployment has already hit

### If every request returns 500

RoadRunner logs `RoadRunner can't communicate with the worker` and `laravel.log` stays
empty, because the worker dies during framework boot before the log handler exists. An
empty `APP_KEY` does exactly this. `php artisan about` still succeeds, because it never
touches the encrypter — so a healthy `about` does not mean the app can serve.

To see the real exception, add `--log-level=debug` to the `octane:start` line in
`/etc/supervisor/conf.d/supervisord.roadrunner.conf` **inside the container**, then
`docker restart` it — a plain restart keeps the edit, whereas
`docker compose up -d --force-recreate` discards it.

### If login succeeds but the panel returns 403

Two independent causes, both producing an identical bare 403 with nothing logged:

- `App\Models\User` must implement `Filament\Models\Contracts\FilamentUser`. Filament's
  `Authenticate` middleware aborts 403 for any user model lacking it whenever `APP_ENV`
  is not `local`, before permissions or tenancy are consulted. This is fixed in the repo.
- The `permissions` table must not be empty. `config/filament-shield.php` sets
  `super_admin.define_via_gate` to false, so that role grants nothing on its own. Check
  with `DB::table('permissions')->count()`; if zero, `PermissionsSeeder` did not run.

### The healthcheck only proves the app serves

`.docker/healthcheck` curls `/up`. It previously ran `octane:status`, which reports a live
process even while every request returns 500 — the container showed `healthy` throughout a
total outage. If you change it, keep it making a real request.

### Anything under .docker/ needs a rebuild

`Dockerfile` copies `.docker/php.ini`, `.docker/healthcheck` and `.docker/start-container`
into the image. Editing them and restarting changes nothing; run
`docker compose build app` first.

### public/build is committed and Docker does not build assets

There is no Node stage in the `Dockerfile` — it copies `public/build` straight from the
repo. Any CSS/JS change needs `npm run build` locally and the output committed, or the
deployed app renders unstyled.
