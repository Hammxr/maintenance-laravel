<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class PermissionsSeeder extends Seeder
{
    /**
     * Panels whose entities need permissions generated.
     *
     * @var list<string>
     */
    private const PANELS = ['app', 'admin'];

    /**
     * Run the database seeds.
     *
     * This used to call a `permissions:sync` command that does not exist in this
     * application, and was commented out — so no permission row was ever created.
     * RolesSeeder then ran `syncPermissions()` against an empty set, leaving
     * super_admin holding the role and nothing else. Because
     * config/filament-shield.php sets `super_admin.define_via_gate` to false there
     * is no Gate::before bypass to fall back on, so every policy check denied and
     * the panels answered a bare 403 on login with nothing written to the log.
     *
     * `--option=permissions` keeps this to database rows: generating policies would
     * write PHP files into the container, which is both wrong for a deploy-time
     * seeder and lost on the next image build.
     */
    public function run(): void
    {
        foreach (self::PANELS as $panel) {
            Artisan::call("shield:generate --all --option=permissions --panel={$panel} --no-interaction");
        }
    }
}
