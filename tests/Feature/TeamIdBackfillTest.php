<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\IotSensorReading;
use App\Models\Team;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the one-shot migrations that adopt records written while panel
 * tenancy was inactive. The migrations already ran (empty) as part of the
 * test database build, so they are re-invoked here against seeded rows.
 */
class TeamIdBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_08_14_000002_backfill_null_team_id_on_tenant_owned_tables.php'
        );

        $migration->up();
    }

    #[Test]
    public function orphaned_records_are_adopted_when_a_single_team_exists(): void
    {
        $team = Team::factory()->create();

        $equipment = Equipment::factory()->create(['team_id' => null]);
        $workOrder = WorkOrder::factory()->create(['team_id' => null]);

        $this->runBackfill();

        $this->assertSame($team->id, $equipment->fresh()->team_id);
        $this->assertSame($team->id, $workOrder->fresh()->team_id);
    }

    #[Test]
    public function already_owned_records_are_left_alone(): void
    {
        $team = Team::factory()->create();
        $equipment = Equipment::factory()->create(['team_id' => $team->id]);

        $this->runBackfill();

        $this->assertSame($team->id, $equipment->fresh()->team_id);
    }

    #[Test]
    public function orphaned_records_are_left_for_triage_when_teams_are_ambiguous(): void
    {
        Team::factory()->count(2)->create();

        $equipment = Equipment::factory()->create(['team_id' => null]);

        $this->runBackfill();

        $this->assertNull(
            $equipment->fresh()->team_id,
            'Guessing an owner across multiple teams would expose data to the wrong tenant.',
        );
    }

    #[Test]
    public function sensor_readings_are_backfilled_from_their_equipment(): void
    {
        $team = Team::factory()->create();
        $equipment = Equipment::factory()->create(['team_id' => $team->id]);

        $migration = require database_path(
            'migrations/2026_08_14_000001_add_team_id_to_iot_sensor_readings_table.php'
        );

        // Rewind to the pre-migration shape, then seed an unowned reading.
        $migration->down();

        $readingId = DB::table('iot_sensor_readings')->insertGetId([
            'equipment_id' => $equipment->id,
            'sensor_type' => 'temperature',
            'metric_name' => 'ambient',
            'value' => 21.5,
            'status' => 'normal',
            'reading_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame($team->id, IotSensorReading::find($readingId)->team_id);
    }
}
