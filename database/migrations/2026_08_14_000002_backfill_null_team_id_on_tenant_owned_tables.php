<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adopt records that were created while Filament panel tenancy was inactive.
 *
 * Until the Jetstream `teams` feature was enabled, the app panel never
 * registered a tenant, so nothing written through the UI was stamped with a
 * `team_id`. Those rows are now invisible in the panel, because the tenancy
 * global scope filters them out.
 *
 * Reassigning them is only unambiguous on a single-team install. When more
 * than one team exists there is no way to tell which team an orphaned row
 * belongs to, and guessing would hand one team another team's data, so the
 * rows are left alone for a human to triage. That failure mode is safe: the
 * records stay hidden rather than surfacing under the wrong tenant.
 */
return new class extends Migration
{
    /**
     * Tables owned by a team, in the sense that Filament scopes them by tenant.
     */
    private const TENANT_OWNED_TABLES = [
        'checklists',
        'companies',
        'contacts',
        'custom_forms',
        'document_tags',
        'documents',
        'equipment',
        'inventory_parts',
        'iot_sensor_readings',
        'maintenance_schedules',
        'notes',
        'opportunities',
        'tasks',
        'vendor_contracts',
        'vendor_performance_evaluations',
        'work_orders',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('teams')) {
            return;
        }

        if (DB::table('teams')->count() !== 1) {
            return;
        }

        $teamId = DB::table('teams')->value('id');

        foreach (self::TENANT_OWNED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            DB::table($table)->whereNull('team_id')->update(['team_id' => $teamId]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * The original owner of each row is not recorded anywhere, so there is
     * nothing to restore. Leaving the backfilled values in place is the only
     * non-destructive option.
     */
    public function down(): void
    {
        //
    }
};
