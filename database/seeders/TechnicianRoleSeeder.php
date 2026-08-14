<?php

namespace Database\Seeders;

use App\Models\Team;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TechnicianRoleSeeder extends Seeder
{
    /**
     * Creates a 'technician' role scoped to exactly what a maintenance
     * technician needs day-to-day:
     *   - Work Orders: view/create/update the ones assigned to them
     *     (row-level scoping to assigned_to happens in WorkOrderResource,
     *     not here — this only controls whether they can use the feature
     *     at all).
     *   - Maintenance Schedules: view/update the ones assigned to them
     *     (same row-level scoping in MaintenanceScheduleResource). No
     *     create permission — schedules are set up by an admin and
     *     assigned to a specific technician.
     *   - Equipment: view only, so they can see what they're working on.
     *   - Inventory Parts: view/update, so they can adjust stock after
     *     performing maintenance, but not create/delete part records.
     *
     * Everything else (Contacts, Companies, Notes, Tasks, Vendors, Reports,
     * Users, Documents, etc.) is deliberately left out, so a technician
     * simply has no permission for those resources and Filament hides them
     * from navigation entirely.
     *
     * Run `php artisan shield:generate --all --panel=app` first so the
     * permissions below actually exist, and re-run RolesSeeder afterward so
     * super_admin picks up any newly generated permissions too.
     */
    public function run(): void
    {
        $roleData = [
            'name' => 'technician',
            'guard_name' => 'web',
        ];

        if (Utils::isTenancyEnabled()) {
            $team = Team::firstOrFail();
            $roleData['team_id'] = $team->id;
        }

        $role = Role::firstOrCreate($roleData);

        $wantedPermissions = [
            'ViewAny:WorkOrder',
            'View:WorkOrder',
            'Create:WorkOrder',
            'Update:WorkOrder',

            'ViewAny:MaintenanceSchedule',
            'View:MaintenanceSchedule',
            'Update:MaintenanceSchedule',

            'ViewAny:Equipment',
            'View:Equipment',

            'ViewAny:InventoryPart',
            'View:InventoryPart',
            'Update:InventoryPart',
        ];

        $foundPermissions = Permission::where('guard_name', 'web')
            ->whereIn('name', $wantedPermissions)
            ->get();

        $missing = array_diff($wantedPermissions, $foundPermissions->pluck('name')->all());

        if ($missing !== []) {
            $this->command?->warn(
                'TechnicianRoleSeeder: these permissions were not found and were skipped '
                . '(run `php artisan shield:generate --all --panel=app` first): '
                . implode(', ', $missing)
            );
        }

        $role->syncPermissions($foundPermissions);

        $this->command?->info(
            'Technician role ready with ' . $foundPermissions->count() . ' permission(s).'
        );
    }
}
