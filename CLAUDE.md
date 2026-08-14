# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A CMMS (Computerised Maintenance Management System) — asset/equipment tracking, preventive
maintenance scheduling, work orders, inventory, vendor management, document control and
reporting. Forked from the open-source [Liberu Maintenance](https://github.com/liberu-maintenance/maintenance-laravel)
project and rebranded to **"Republic Lifestyle"**. The rebrand is intentional — don't "correct"
brand strings back to Liberu.

## Stack

| | |
|---|---|
| PHP | 8.5 (`composer.json` requires `^8.5`) |
| Laravel | **13.x** (13.13.0 installed) — the README's "Laravel 12" badge is stale, trust `composer.lock` |
| Admin UI | Filament 5 + Livewire 4 |
| Auth/teams | Jetstream 5 + Fortify + Socialstream (social login) |
| Permissions | spatie/laravel-permission 7 + filament-shield 4 (team-scoped) |
| Runtime | Laravel Octane on **RoadRunner** (not Swoole — see Deployment) |
| Queues | Redis + Horizon |
| Database | MySQL 8 in dev/prod; **SQLite in-memory** for tests |

## Commands

```bash
composer install && npm ci
php artisan migrate --seed        # seeders include demo data — see Seeders below
npm run dev                       # Vite dev server
npm run build                     # production assets — see Frontend assets
```

Tests use **PHPUnit 13 directly, not Pest**:

```bash
php artisan test                              # all
php artisan test --testsuite=Unit             # Unit or Feature
php artisan test tests/Unit/Models/TaskTest.php   # single file
php artisan test --filter=test_method_name    # single test
```

Code style and refactoring (no `pint.json`, so Pint uses its Laravel preset):

```bash
./vendor/bin/pint                 # format
./vendor/bin/pint --test          # check only
./vendor/bin/rector process       # PHP 8.5 + Laravel 13 + code-quality sets
./vendor/bin/rector process --dry-run
```

Docker for local dev:

```bash
docker compose up -d              # app + mysql + redis
docker compose --profile horizon up -d      # optional: horizon, worker, reverb, mail
```

## Architecture

### Teams are the tenant — this drives almost everything

Jetstream `Team` is registered as the Filament tenant on the app panel
(`AppPanelProvider::panel()`, `->tenant(Team::class, ownershipRelationship: 'team')`), gated
behind `Features::hasTeamFeatures()`. Nearly every domain model carries a `team_id`.

**Filament does the scoping and the stamping — don't hand-roll either.** When the panel has
tenancy, Filament registers two things per resource (see
`Filament\Resources\Resource\Concerns\BelongsToTenant`):

- a **model-level global scope** that filters every query on that model — including relationship
  queries behind a form `Select` or a `SelectFilter`, not just resource list queries;
- a **`creating` hook** that associates the current tenant with new records.

Both are registered when the panel boots, so per-resource `getEloquentQuery()` overrides,
`modifyFormDataBeforeCreate()` tenant stamping, and `where('team_id', ...)` in a Select's
`modifyQueryUsing` are all redundant. Panel URLs are `/app/{tenant}/...`; bare `/app` redirects
to the default tenant.

Because the scoping is a global scope, **Laravel's `unique` and `exists` validation rules bypass
it** — they would report a clash against another team's record and leak its existence. Use
Filament's `scopedUnique()` / `scopedExists()` on tenant-owned fields instead. `sensor_id` on
equipment is a deliberate exception: it stays globally unique because `IotSensorController` looks
equipment up by it across teams.

Two middleware also matter, and both are easy to miss:

- **`AssignDefaultTeam`** (appended to the `web` group in `bootstrap/app.php`) — if no tenant is
  set for an authenticated user, it falls back to `currentTeam`, then `ownedTeams()->first()`,
  and creates a personal team if neither exists.
- **`TeamsPermission`** — bridges the Filament tenant into spatie/permission's team context via
  `setPermissionsTeamId($team->id)`. **Permission checks silently resolve against the wrong team
  if this hasn't run.** Any new panel, console command or queued job that evaluates permissions
  outside an HTTP request must set the team id itself.

Queries outside the panel — the API, console commands, queued jobs — are **not** scoped, because
there's no current panel. They must filter by team themselves.

A new tenant-owned model needs `team_id` in `$fillable` **and** a `team()` `BelongsTo`
relationship. Filament resolves that relationship name inside the global scope and throws
`LogicException` if it's missing.

`User` implements `HasTenants` and uses Jetstream's `HasTeams`. Both `HasTeams` and Spatie's
`HasRoles` declare `teams()`; the collision is resolved in favour of Jetstream's, with Spatie's
aliased to `permissionTeams`.

**Testing tenancy:** `Filament::setCurrentPanel()` alone is not enough — the global scope and
`creating` hook are only registered by `Filament::bootCurrentPanel()`. Create fixtures belonging
to *other* teams **before** calling `Filament::setTenant()`, or the `creating` hook will re-stamp
them onto the current tenant. See `tests/Feature/PanelTenancyTest.php` and
`tests/Feature/Livewire/EquipmentLineLeaderTest.php`.

### "Line Leader" is not a team

`Equipment` carries two team-ish columns and they mean different things:

| Column | Meaning |
|---|---|
| `team_id` | The **tenant** that owns the record. Scopes every query. Set by Filament. |
| `line_leader_id` | An **optional grouping label** within one tenant, so technicians can filter equipment by whoever it's filed under. |

Line leaders mirror a filing system the technicians already used off-system. They are a lookup
table rather than free text (typos would fragment the filter, which is the whole point of the
field) and deliberately **not** a foreign key to `users` — a line leader doesn't need a login.
Deleting one nulls `equipment.line_leader_id` rather than cascading.

### Two Filament panels

- `/app` — `AppPanelProvider`, marked `->default()`. Tenant-scoped, registration + password
  reset + email verification, ~17 resources, ~14 dashboard widgets.
- `/admin` — `AdminPanelProvider`, hosts `FilamentShieldPlugin` for role/permission management.

Both deliberately share one compiled stylesheet (`resources/css/filament/app/theme.css`) so the
panels can't drift apart visually. Don't add a second admin theme.

### Non-standard primary keys

Several models override `$primaryKey` rather than using `id`:

| Model | PK |
|---|---|
| `Task` | `task_id` |
| `Company` | `company_id` |
| `Contact` | `contact_id` |
| `Note` | `note_id` |
| `Opportunity` | `opportunity_id` |

These were originally created as plain `integer()->primary()` columns without AUTO_INCREMENT,
which made every `create()` fail with "Field 'xxx_id' doesn't have a default value". Fixed by
`database/migrations/2026_07_31_120000_add_auto_increment_to_custom_primary_keys.php` — read its
docblock before touching these tables. New tables should use conventional `id` unless there's a
reason not to.

### Maintenance automation pipeline

The product's core behaviour, spread across several files:

1. A `MaintenanceSchedule` becomes due either by **calendar date** (`next_due_date`) or by
   **equipment operating hours** (`frequency_type === 'hours'`) — `MaintenanceSchedule::isDue()`.
2. `MaintenanceSchedulingService::checkSchedule()` turns a due schedule into a `WorkOrder`,
   skipping schedules that already have an open one (`pending`/`approved`/`in_progress`). Work
   orders are created pre-`approved` so they land straight in the technician's queue.
3. Two trigger paths:
   - **Batch** — `maintenance:generate-work-orders`, daily 06:00 (`app/Console/Kernel.php`).
   - **Immediate** — `checkSchedulesForEquipment()` runs the moment an equipment's
     `current_hours` is updated, so hour-based schedules don't wait for the daily run.
4. Reminders fire separately via `maintenance:send-reminders --days=3|1|0` and
   `maintenance:check-due --days=7`, all scheduled in `app/Console/Kernel.php`.

**This means `schedule:run` must be running**, or preventive maintenance silently stops. Schedule
priority `critical` has no work-order equivalent and is mapped to `urgent`.

### Services

Business logic that doesn't belong in models or Filament resources lives in `app/Services/`:
`MaintenanceSchedulingService`, `MaintenanceReportService`, `InventoryService`, `IotSensorService`.
Prefer extending these over putting logic in Filament resource classes.

### Module system

A homegrown plugin system in `app/Modules/` (`ModuleManager`, `BaseModule`, `ModuleInterface`,
lifecycle events, `ExternalModuleLoader`), configured by `config/modules.php` with auto-discovery
and caching. Scaffold with `php artisan make:module` (`MakeModuleCommand`); manage with
`php artisan module` (`ModuleCommand`). Covered by `tests/Feature/ModuleSystemTest.php`.

### API

`routes/api.php` exposes `/api/v1/*` as Sanctum-authenticated `apiResource` routes (equipment,
work orders, maintenance schedules, inventory parts, documents, contacts, companies, checklists,
notes, tasks, opportunities), controllers in `app/Http/Controllers/Api/V1/`.

Note the IoT endpoints: `POST /api/iot-sensors/readings` and `/readings/batch` are
**deliberately unauthenticated** so devices can post without a token — the code comments flag
this as something to protect later. Everything else under `/api/iot-sensors` requires Sanctum.

## Things that will trip you up

### Frontend assets are committed, and Docker won't build them

`public/build/` is **not** gitignored and the Dockerfile has **no Node stage** — it copies
`public/build` straight from the repo. Any change to CSS/JS requires running `npm run build` and
committing the output, or the deployed app renders unstyled.

### Container modes

One image, many roles, selected by `CONTAINER_MODE` (`http`/`horizon`/`reverb`/`scheduler`/
`worker`) in `.docker/start-container`. In `http` mode, `WITH_HORIZON`, `WITH_SCHEDULER` and
`WITH_REVERB` toggle extra supervisord programs inside the same container. `start-container` also
runs `optimize:clear` + `config:cache` + `route:cache` on every boot, so **env changes need a
container restart**, not just a file edit.

`OCTANE_SERVER` must be `roadrunner`. `.env.example` ships `swoole`, but the image only installs
RoadRunner and only ships the RoadRunner supervisord config — `swoole` fails at startup.

### Seeders mix structural and demo data

`DatabaseSeeder` calls both. Structural (required for the app to function): `PermissionsSeeder`,
`RolesSeeder`, `TechnicianRoleSeeder`, `MenuSeeder`, `TeamSeeder`. Demo data (do not run in
production): `CompanySeeder`, `EquipmentSeeder`, `ChecklistSeeder`, `MaintenanceScheduleSeeder`,
`WorkOrderSeeder`, `InventorySeeder`.

`UserSeeder` creates `admin@example.com` with a random password printed to stdout, and depends on
`TeamSeeder` having run (it calls `Team::firstOrFail()`).

### There are two TrustProxies, and one is dead code

`bootstrap/app.php` calls `$middleware->trustProxies(at: '*')` — that is the live configuration,
and it's what stops Laravel generating `http://` URLs behind a TLS-terminating proxy (which
breaks Livewire and Filament asset loading).

`app/Http/Middleware/TrustProxies.php` is **dead code** left over from the Laravel 10 skeleton.
There is no `app/Http/Kernel.php` and nothing registers that class. Editing it has no effect.

## Conventions

- Newer files use `declare(strict_types=1);`; older ones don't. Match the file you're editing.
- Comments in this codebase explain *why*, often at length, on non-obvious workarounds (see the
  custom-PK migration and `MaintenanceSchedulingService`). Follow that when the reasoning isn't
  self-evident from the code.
- Model changes are audited via `spatie/laravel-activitylog`, surfaced in the Change Log Report page.

## Deployment

Production target is a single GCP Compute Engine VM running Docker Compose — full runbook in
[docs/DEPLOYMENT_GCP_VM.md](docs/DEPLOYMENT_GCP_VM.md). Kubernetes manifests exist under `k8s/`
as an alternative path but are not the current deployment route.

Further feature documentation lives in `docs/` (documentation management, inventory, IoT sensors,
modular architecture, preventive maintenance scheduler, vendor management, work orders).
