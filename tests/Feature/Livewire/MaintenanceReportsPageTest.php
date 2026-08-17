<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Filament\App\Pages\MaintenanceReports;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reports page fails in the browser with an empty 500: the Octane worker
 * dies while reporting the exception, so nothing reaches the log. Mounting the
 * page here surfaces whatever it actually throws.
 */
class MaintenanceReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private Panel $panel;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
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
    public function the_reports_page_mounts(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(MaintenanceReports::class)
            ->assertSuccessful();
    }

    #[Test]
    public function a_report_can_be_generated(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(MaintenanceReports::class)
            ->fillForm([
                'start_date' => now()->subDays(30)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ])
            ->call('generateReport')
            ->assertHasNoFormErrors()
            ->assertSuccessful();
    }

    #[Test]
    public function a_generated_report_can_be_exported_as_pdf(): void
    {
        Filament::setTenant($this->team);

        Livewire::test(MaintenanceReports::class)
            ->fillForm([
                'start_date' => now()->subDays(30)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ])
            ->call('generateReport')
            ->call('exportPdf')
            ->assertSuccessful();
    }
}
