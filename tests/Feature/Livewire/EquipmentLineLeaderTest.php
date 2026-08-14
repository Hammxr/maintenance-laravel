<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Filament\App\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\App\Resources\Equipment\Pages\ListEquipment;
use App\Filament\App\Resources\LineLeaders\Pages\ListLineLeaders;
use App\Models\Equipment;
use App\Models\LineLeader;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the Filament layer of the Line Leader feature, which the model tests
 * in tests/Unit/Models/LineLeaderTest.php can't reach: a resource can be
 * configured with a field name that doesn't exist, a relationship Filament
 * can't resolve, or an API that moved between Filament versions, and none of
 * that shows up until the page is actually mounted.
 */
class EquipmentLineLeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user);

        $this->actingAs($this->user);

        // These resources live on the tenant-scoped app panel, so both the
        // panel and the tenant have to be set by hand — outside an HTTP
        // request there's no middleware to do it.
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->team);
    }

    #[Test]
    public function the_equipment_form_has_an_optional_line_leader_field(): void
    {
        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('line_leader_id')
            ->assertFormFieldVisible('line_leader_id')
            ->assertFormFieldEnabled('line_leader_id');
    }

    #[Test]
    public function equipment_can_be_created_without_choosing_a_line_leader(): void
    {
        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'name' => 'Unassigned Press',
                'status' => 'active',
                'criticality' => 'medium',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('equipment', [
            'name' => 'Unassigned Press',
            'line_leader_id' => null,
        ]);
    }

    #[Test]
    public function equipment_can_be_created_with_a_line_leader(): void
    {
        $leader = LineLeader::create(['name' => 'Line 3 Leader', 'team_id' => $this->team->id]);

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'name' => 'Assigned Press',
                'status' => 'active',
                'criticality' => 'medium',
                'line_leader_id' => $leader->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('equipment', [
            'name' => 'Assigned Press',
            'line_leader_id' => $leader->id,
        ]);
    }

    #[Test]
    public function the_equipment_list_exposes_a_line_leader_column_and_filter(): void
    {
        Livewire::test(ListEquipment::class)
            ->assertTableColumnExists('lineLeader.name')
            ->assertTableFilterExists('line_leader_id');
    }

    /**
     * The reason the feature exists — a technician narrowing the list to one
     * line leader's machines.
     */
    #[Test]
    public function filtering_by_line_leader_narrows_the_equipment_list(): void
    {
        $first = LineLeader::create(['name' => 'Line 1 Leader', 'team_id' => $this->team->id]);
        $second = LineLeader::create(['name' => 'Line 2 Leader', 'team_id' => $this->team->id]);

        $mine = Equipment::factory()->count(2)->create([
            'team_id' => $this->team->id,
            'line_leader_id' => $first->id,
        ]);
        $theirs = Equipment::factory()->create([
            'team_id' => $this->team->id,
            'line_leader_id' => $second->id,
        ]);
        $unassigned = Equipment::factory()->create([
            'team_id' => $this->team->id,
            'line_leader_id' => null,
        ]);

        Livewire::test(ListEquipment::class)
            ->assertCanSeeTableRecords($mine)
            ->filterTable('line_leader_id', $first->id)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$theirs, $unassigned]);
    }

    /**
     * The dropdown and filter are scoped to the tenant by hand, because
     * Filament doesn't apply tenancy to a Select's relationship query. If that
     * scoping is ever dropped, another team's line leaders leak into both.
     */
    #[Test]
    public function the_line_leader_options_are_scoped_to_the_current_team(): void
    {
        $ours = LineLeader::create(['name' => 'Our Leader', 'team_id' => $this->team->id]);
        $theirs = LineLeader::create(['name' => 'Their Leader', 'team_id' => Team::factory()->create()->id]);

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('line_leader_id', checkFieldUsing: function ($field) use ($ours, $theirs): bool {
                $options = $field->getOptions();

                return array_key_exists($ours->id, $options)
                    && ! array_key_exists($theirs->id, $options);
            });
    }

    #[Test]
    public function the_line_leaders_list_page_renders(): void
    {
        LineLeader::create(['name' => 'Line 3 Leader', 'team_id' => $this->team->id]);

        Livewire::test(ListLineLeaders::class)
            ->assertSuccessful()
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('equipment_count');
    }
}
