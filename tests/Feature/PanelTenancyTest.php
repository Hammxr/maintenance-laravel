<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\IotSensorReading;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the app panel's multi-tenancy wiring.
 *
 * Filament only registers its tenancy global scope and its create-time tenant
 * association when the panel actually has a tenant configured, and the panel
 * only configures one when Jetstream's `teams` feature is enabled. With the
 * feature off, every resource silently wrote `team_id = null` and every list
 * showed all teams' records.
 */
class PanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Panel $panel;

    private Team $team;

    private Team $otherTeam;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create(['name' => 'Alpha']);
        $this->otherTeam = Team::factory()->create(['name' => 'Beta']);

        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user);

        $this->panel = Filament::getPanel('app');

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
    public function app_panel_has_tenancy_configured(): void
    {
        $this->assertTrue($this->panel->hasTenancy());
        $this->assertSame(Team::class, $this->panel->getTenantModel());
        $this->assertSame('team', $this->panel->getTenantOwnershipRelationshipName());
    }

    #[Test]
    public function user_satisfies_the_filament_tenancy_contract(): void
    {
        $this->assertInstanceOf(HasTenants::class, $this->user);
        $this->assertTrue($this->user->canAccessTenant($this->team));
        $this->assertFalse($this->user->canAccessTenant($this->otherTeam));
        $this->assertEqualsCanonicalizing(
            [$this->team->id],
            $this->user->getTenants($this->panel)->pluck('id')->all(),
        );
    }

    #[Test]
    public function every_tenant_scoped_app_resource_has_a_team_ownership_relationship(): void
    {
        // Filament resolves the ownership relationship inside the tenancy
        // global scope, so a scoped resource whose model lacks `team()` throws
        // a LogicException the moment anyone opens its list page.
        foreach ($this->panel->getResources() as $resource) {
            if (! $resource::isScopedToTenant()) {
                continue;
            }

            $model = $resource::getModel();

            $this->assertTrue(
                (new $model)->isRelation($resource::getTenantOwnershipRelationshipName()),
                "[{$resource}] is tenant-scoped but [{$model}] has no ownership relationship.",
            );
        }
    }

    #[Test]
    public function creating_a_record_inside_the_panel_stamps_the_tenant(): void
    {
        Filament::setTenant($this->team);

        $equipment = Equipment::create([
            'name' => 'Dye-Sub Printer',
            'status' => 'active',
            'criticality' => 'medium',
        ]);

        $this->assertSame($this->team->id, $equipment->team_id);
    }

    #[Test]
    public function queries_inside_the_panel_are_scoped_to_the_tenant(): void
    {
        $mine = Equipment::factory()->create(['team_id' => $this->team->id]);
        $theirs = Equipment::factory()->create(['team_id' => $this->otherTeam->id]);
        $orphan = Equipment::factory()->create(['team_id' => null]);

        Filament::setTenant($this->team);

        $visible = Equipment::query()->pluck('id')->all();

        $this->assertContains($mine->id, $visible);
        $this->assertNotContains($theirs->id, $visible, 'Another team\'s equipment leaked into the panel.');
        $this->assertNotContains($orphan->id, $visible, 'Unowned equipment leaked into the panel.');
    }

    #[Test]
    public function scoping_survives_a_tenant_switch(): void
    {
        $mine = Equipment::factory()->create(['team_id' => $this->team->id]);
        $theirs = Equipment::factory()->create(['team_id' => $this->otherTeam->id]);

        $this->otherTeam->users()->attach($this->user);

        Filament::setTenant($this->team);
        $this->assertSame([$mine->id], Equipment::query()->pluck('id')->all());

        Filament::setTenant($this->otherTeam);
        $this->assertSame([$theirs->id], Equipment::query()->pluck('id')->all());
    }

    #[Test]
    public function switching_tenant_moves_the_users_current_team(): void
    {
        $this->otherTeam->users()->attach($this->user);

        Filament::setTenant($this->otherTeam);

        $this->assertSame($this->otherTeam->id, $this->user->fresh()->current_team_id);
    }

    #[Test]
    public function queries_outside_the_panel_are_not_scoped(): void
    {
        // The API and the console run without a current panel, and already do
        // their own `current_team_id` filtering.
        Equipment::factory()->create(['team_id' => $this->team->id]);
        Equipment::factory()->create(['team_id' => $this->otherTeam->id]);

        Filament::setCurrentPanel(null);

        $this->assertCount(2, Equipment::query()->get());
    }

    #[Test]
    public function sensor_readings_inherit_the_team_of_their_equipment(): void
    {
        $equipment = Equipment::factory()->create(['team_id' => $this->otherTeam->id]);

        // Ingested from the sensor API, i.e. with no panel tenant in play.
        Filament::setCurrentPanel(null);

        $reading = IotSensorReading::create([
            'equipment_id' => $equipment->id,
            'sensor_type' => 'temperature',
            'metric_name' => 'ambient',
            'value' => 21.5,
            'unit' => 'C',
            'reading_time' => now(),
        ]);

        $this->assertSame($this->otherTeam->id, $reading->team_id);
    }
}
