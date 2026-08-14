<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Equipment;
use App\Models\LineLeader;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LineLeaderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_expected_fillable_attributes(): void
    {
        $fillable = (new LineLeader)->getFillable();

        $this->assertContains('name', $fillable);
        $this->assertContains('notes', $fillable);
        $this->assertContains('team_id', $fillable);
    }

    #[Test]
    public function equipment_can_be_created_without_a_line_leader(): void
    {
        $equipment = Equipment::factory()->create(['line_leader_id' => null]);

        $this->assertNull($equipment->line_leader_id);
        $this->assertNull($equipment->lineLeader);
    }

    #[Test]
    public function equipment_belongs_to_a_line_leader(): void
    {
        $leader = LineLeader::create(['name' => 'Line 3 Leader']);
        $equipment = Equipment::factory()->create(['line_leader_id' => $leader->id]);

        $this->assertInstanceOf(LineLeader::class, $equipment->lineLeader);
        $this->assertSame($leader->id, $equipment->lineLeader->id);
    }

    #[Test]
    public function a_line_leader_has_many_equipment(): void
    {
        $leader = LineLeader::create(['name' => 'Line 3 Leader']);
        Equipment::factory()->count(2)->create(['line_leader_id' => $leader->id]);
        Equipment::factory()->create(['line_leader_id' => null]);

        $this->assertCount(2, $leader->equipment);
    }

    /**
     * The whole point of the field — technicians filtering the equipment list
     * down to one line leader's machines.
     */
    #[Test]
    public function equipment_can_be_filtered_by_line_leader(): void
    {
        $first = LineLeader::create(['name' => 'Line 1 Leader']);
        $second = LineLeader::create(['name' => 'Line 2 Leader']);

        Equipment::factory()->count(3)->create(['line_leader_id' => $first->id]);
        Equipment::factory()->count(2)->create(['line_leader_id' => $second->id]);
        Equipment::factory()->create(['line_leader_id' => null]);

        $this->assertCount(3, Equipment::where('line_leader_id', $first->id)->get());
        $this->assertCount(2, Equipment::where('line_leader_id', $second->id)->get());
        $this->assertCount(1, Equipment::whereNull('line_leader_id')->get());
    }

    #[Test]
    public function deleting_a_line_leader_unassigns_its_equipment_rather_than_deleting_it(): void
    {
        $leader = LineLeader::create(['name' => 'Line 3 Leader']);
        $equipment = Equipment::factory()->create(['line_leader_id' => $leader->id]);

        $leader->delete();

        $this->assertDatabaseHas('equipment', [
            'id' => $equipment->id,
            'line_leader_id' => null,
        ]);
    }

    #[Test]
    public function it_belongs_to_a_team(): void
    {
        $team = Team::factory()->create();
        $leader = LineLeader::create(['name' => 'Line 3 Leader', 'team_id' => $team->id]);

        $this->assertInstanceOf(Team::class, $leader->team);
        $this->assertSame($team->id, $leader->team->id);
    }
}
