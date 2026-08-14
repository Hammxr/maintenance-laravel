<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Filament\App\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\App\Resources\Equipment\Pages\ListEquipment;
use App\Filament\App\Resources\LineLeaders\Pages\CreateLineLeader;
use App\Filament\App\Resources\LineLeaders\Pages\ListLineLeaders;
use App\Models\Equipment;
use App\Models\LineLeader;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the Filament layer of the Line Leader feature, which the model tests
 * in tests/Unit/Models/LineLeaderTest.php can't reach: a resource can be
 * configured with a field name that doesn't exist, a relationship Filament
 * can't resolve, or an API that moved between versions, and none of that
 * surfaces until the page is actually mounted.
 *
 * Tenant-owned fixtures are created *before* Filament::setTenant(), because the
 * panel's create-time association would otherwise re-stamp them onto the
 * current tenant and a record belonging to another team could not be set up at
 * all. Same reason PanelTenancyTest orders things this way.
 */
class EquipmentLineLeaderTest extends TestCase
{
    use RefreshDatabase;

    private Panel $panel;

    private User $user;

    private Team $team;

    private Team $otherTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create(['name' => 'Alpha']);
        $this->otherTeam = Team::factory()->create(['name' => 'Beta']);

        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user);

        $this->panel = Filament::getPanel('app');

        // bootCurrentPanel() is what registers the tenancy global scope and the
        // create-time association; setting the panel alone leaves both off.
        Filament::setCurrentPanel($this->panel);
        Filament::bootCurrentPanel();

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    #[Test]
    public function the_equipment_form_has_an_optional_line_leader_field(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('line_leader_id')
            ->assertFormFieldVisible('line_leader_id')
            ->assertFormFieldEnabled('line_leader_id');
    }

    #[Test]
    public function equipment_can_be_created_without_choosing_a_line_leader(): void
    {
        Filament::setTenant($this->team);

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
            'team_id' => $this->team->id,
        ]);
    }

    #[Test]
    public function equipment_can_be_created_with_a_line_leader(): void
    {
        $leader = LineLeader::create(['name' => 'Line 3 Leader', 'team_id' => $this->team->id]);

        Filament::setTenant($this->team);

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
        Filament::setTenant($this->team);

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

        Filament::setTenant($this->team);

        Livewire::test(ListEquipment::class)
            ->assertCanSeeTableRecords($mine)
            ->filterTable('line_leader_id', $first->id)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$theirs, $unassigned]);
    }

    /**
     * The dropdown must not offer another team's line leaders. This is the
     * panel's tenancy global scope doing the work rather than anything in the
     * resource, so it is worth asserting that it actually reaches a Select's
     * relationship query.
     */
    #[Test]
    public function the_line_leader_options_are_scoped_to_the_current_team(): void
    {
        $ours = LineLeader::create(['name' => 'Our Leader', 'team_id' => $this->team->id]);
        $theirs = LineLeader::create(['name' => 'Their Leader', 'team_id' => $this->otherTeam->id]);

        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('line_leader_id', checkFieldUsing: function ($field) use ($ours, $theirs): bool {
                $options = $field->getOptions();

                return array_key_exists($ours->id, $options)
                    && ! array_key_exists($theirs->id, $options);
            });
    }

    /**
     * A line leader with no team is invisible to the tenant-scoped dropdown and
     * filter — created successfully, then usable nowhere. This asserts the
     * panel stamps the tenant on records made from the resource's own page.
     */
    #[Test]
    public function a_line_leader_created_from_its_own_page_belongs_to_the_current_team(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(CreateLineLeader::class)
            ->fillForm(['name' => 'Line 1 Leader'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('line_leaders', [
            'name' => 'Line 1 Leader',
            'team_id' => $this->team->id,
        ]);
    }

    #[Test]
    public function the_line_leaders_list_only_shows_the_current_teams_leaders(): void
    {
        $ours = LineLeader::create(['name' => 'Our Leader', 'team_id' => $this->team->id]);
        $theirs = LineLeader::create(['name' => 'Their Leader', 'team_id' => $this->otherTeam->id]);

        Filament::setTenant($this->team);

        Livewire::test(ListLineLeaders::class)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    #[Test]
    public function the_line_leaders_list_page_renders(): void
    {
        LineLeader::create(['name' => 'Line 3 Leader', 'team_id' => $this->team->id]);

        Filament::setTenant($this->team);

        Livewire::test(ListLineLeaders::class)
            ->assertSuccessful()
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('equipment_count');
    }
}
