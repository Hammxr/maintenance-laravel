<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Filament\App\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\App\Resources\Equipment\Pages\ListEquipment;
use App\Filament\App\Resources\EquipmentCategories\Pages\CreateEquipmentCategory;
use App\Filament\App\Resources\EquipmentCategories\Pages\ListEquipmentCategories;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Categories were a hardcoded list of eight options that could only be extended
 * by editing code. They are now a per-team lookup, so each company can name its
 * own — which means the same tenancy and filtering guarantees as line leaders.
 *
 * Fixtures for other teams are created before Filament::setTenant(), or the
 * panel's create-time association re-stamps them onto the current tenant.
 */
class EquipmentCategoryTest extends TestCase
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
    public function the_equipment_form_has_an_optional_category_field(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('equipment_category_id')
            ->assertFormFieldVisible('equipment_category_id');
    }

    #[Test]
    public function equipment_can_be_created_without_a_category(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'name' => 'Uncategorised Rig',
                'status' => 'active',
                'criticality' => 'medium',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('equipment', [
            'name' => 'Uncategorised Rig',
            'equipment_category_id' => null,
        ]);
    }

    #[Test]
    public function equipment_can_be_created_with_a_custom_category(): void
    {
        $category = EquipmentCategory::create(['name' => 'Dye Sublimation', 'team_id' => $this->team->id]);

        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'name' => 'Dye-Sub Printer',
                'status' => 'active',
                'criticality' => 'medium',
                'equipment_category_id' => $category->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('equipment', [
            'name' => 'Dye-Sub Printer',
            'equipment_category_id' => $category->id,
        ]);
    }

    #[Test]
    public function a_category_created_from_its_own_page_belongs_to_the_current_team(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(CreateEquipmentCategory::class)
            ->fillForm(['name' => 'Refrigeration'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('equipment_categories', [
            'name' => 'Refrigeration',
            'team_id' => $this->team->id,
        ]);
    }

    #[Test]
    public function category_options_are_scoped_to_the_current_team(): void
    {
        $ours = EquipmentCategory::create(['name' => 'Packaging', 'team_id' => $this->team->id]);
        $theirs = EquipmentCategory::create(['name' => 'Refrigeration', 'team_id' => $this->otherTeam->id]);

        Filament::setTenant($this->team);

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldExists('equipment_category_id', checkFieldUsing: function ($field) use ($ours, $theirs): bool {
                $options = $field->getOptions();

                return array_key_exists($ours->id, $options)
                    && ! array_key_exists($theirs->id, $options);
            });
    }

    #[Test]
    public function filtering_by_category_narrows_the_equipment_list(): void
    {
        $packaging = EquipmentCategory::create(['name' => 'Packaging', 'team_id' => $this->team->id]);
        $conveying = EquipmentCategory::create(['name' => 'Conveying', 'team_id' => $this->team->id]);

        $mine = Equipment::factory()->count(2)->create([
            'team_id' => $this->team->id,
            'equipment_category_id' => $packaging->id,
        ]);
        $theirs = Equipment::factory()->create([
            'team_id' => $this->team->id,
            'equipment_category_id' => $conveying->id,
        ]);

        Filament::setTenant($this->team);

        Livewire::test(ListEquipment::class)
            ->filterTable('equipment_category_id', $packaging->id)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    #[Test]
    public function deleting_a_category_leaves_its_equipment_uncategorised(): void
    {
        $category = EquipmentCategory::create(['name' => 'Packaging', 'team_id' => $this->team->id]);
        $equipment = Equipment::factory()->create([
            'team_id' => $this->team->id,
            'equipment_category_id' => $category->id,
        ]);

        $category->delete();

        $this->assertDatabaseHas('equipment', [
            'id' => $equipment->id,
            'equipment_category_id' => null,
        ]);
    }

    #[Test]
    public function the_categories_list_only_shows_the_current_teams_categories(): void
    {
        $ours = EquipmentCategory::create(['name' => 'Packaging', 'team_id' => $this->team->id]);
        $theirs = EquipmentCategory::create(['name' => 'Refrigeration', 'team_id' => $this->otherTeam->id]);

        Filament::setTenant($this->team);

        Livewire::test(ListEquipmentCategories::class)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
