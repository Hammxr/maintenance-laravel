<?php

namespace App\Filament\App\Pages;

use App\Services\MaintenanceReportService;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class MaintenanceReports extends Page
{
    #[\Override]
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    #[\Override]
    protected string $view = 'filament.app.pages.maintenance-reports';

    #[\Override]
    protected static string | \UnitEnum | null $navigationGroup = 'Reports';

    #[\Override]
    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public ?array $reportData = null;

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->subDays(30)->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Report Parameters')
                    ->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Start Date')
                            ->required()
                            ->default(now()->subDays(30)),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('End Date')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function generateReport(): void
    {
        $data = $this->form->getState();
        
        // Without startOfDay()/endOfDay(), a date-only value like
        // "2026-08-03" parses to midnight at the very start of that day, so
        // anything completed later that same day (e.g. 10:13am) was being
        // silently excluded from every calculation in this report.
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = Carbon::parse($data['end_date'])->endOfDay();

        if ($endDate->lt($startDate)) {
            Notification::make()
                ->title('Invalid Date Range')
                ->body('End date must be after start date.')
                ->danger()
                ->send();
            return;
        }

        $teamId = filament()->getTenant()?->id;
        $reportService = app(MaintenanceReportService::class);

        $this->reportData = $reportService->generateComprehensiveReport($teamId, $startDate, $endDate);

        Notification::make()
            ->title('Report Generated')
            ->body('The maintenance report has been generated successfully.')
            ->success()
            ->send();
    }

    public function exportPdf(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        // dompdf is memory-hungry; bump the limit for this request only so a
        // large report can't exhaust the Octane worker's default allowance
        // and kill the process mid-render.
        ini_set('memory_limit', '1024M');

        if (!$this->reportData) {
            Notification::make()
                ->title('No Report Data')
                ->body('Please generate a report first.')
                ->warning()
                ->send();
            return null;
        }

        // Render to a plain HTML string first, then sanitize that string directly.
        // Sanitizing the PHP report array beforehand isn't reliable here: it can
        // miss data that isn't a plain string/array by the time it's echoed (e.g.
        // values coerced via __toString, or anything nested in a way the walker
        // doesn't anticipate). Checking the final rendered HTML instead guarantees
        // we catch exactly what dompdf itself will see.
        $html = view('reports.maintenance-comprehensive', [
            'report' => $this->reportData,
        ])->render();

        $html = $this->sanitizeUtf8($html);

        $pdf = Pdf::loadHTML($html);

        // Livewire actions that return raw binary content must use
        // streamDownload() rather than a plain Illuminate\Http\Response.
        // Livewire specifically intercepts StreamedResponse to send it as a
        // direct binary download; anything else gets folded into Livewire's
        // normal JSON response, and json_encode-ing raw PDF bytes (which are
        // not valid UTF-8 text) fails with "Malformed UTF-8 characters".
        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'maintenance-report-' . now()->format('Y-m-d') . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Guarantee a valid UTF-8 string before it reaches dompdf, which throws a
     * hard InvalidArgumentException on any malformed byte sequence (commonly
     * caused by text originally pasted from Word/Outlook and stored under the
     * wrong encoding). Uses the same validity check dompdf itself uses
     * (preg_match with the 'u' modifier), so this can't disagree with dompdf
     * about what counts as valid.
     */
    protected function sanitizeUtf8(string $html): string
    {
        if (preg_match('//u', $html) === 1) {
            return $html;
        }

        // Most likely Windows-1252/Latin-1 data (smart quotes, em dashes, etc.).
        $converted = @mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        if ($converted !== false && preg_match('//u', $converted) === 1) {
            return $converted;
        }

        // Try stripping just the invalid byte sequences and keep everything else.
        $stripped = @iconv('UTF-8', 'UTF-8//IGNORE', $html);
        if ($stripped !== false && preg_match('//u', $stripped) === 1) {
            return $stripped;
        }

        // Absolute last resort: drop any non-ASCII byte so the PDF still generates.
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $html);
    }

    /**
     * A separate PDF, deliberately not tied to the date-range form above —
     * "overdue" is a snapshot of right now, not something scoped to a
     * period, so this can be exported independently of generating the main
     * period report first.
     */
    public function exportOverduePdf(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        ini_set('memory_limit', '1024M');

        $teamId = filament()->getTenant()?->id;
        $reportService = app(MaintenanceReportService::class);

        $overdue = $reportService->getOverdueMaintenance($teamId);

        $html = view('reports.overdue-maintenance', [
            'overdue' => $overdue,
        ])->render();

        $html = $this->sanitizeUtf8($html);

        $pdf = Pdf::loadHTML($html);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'overdue-maintenance-' . now()->format('Y-m-d') . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Unplanned maintenance, like the main report, is scoped to a date
     * range — but reads it directly off the form's currently selected
     * dates rather than requiring "Generate Report" to have been clicked
     * first, since someone may only want this one report.
     */
    public function exportUnplannedPdf(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        ini_set('memory_limit', '1024M');

        $data = $this->form->getState();
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = Carbon::parse($data['end_date'])->endOfDay();

        if ($endDate->lt($startDate)) {
            Notification::make()
                ->title('Invalid Date Range')
                ->body('End date must be after start date.')
                ->danger()
                ->send();
            return null;
        }

        $teamId = filament()->getTenant()?->id;
        $reportService = app(MaintenanceReportService::class);

        $unplanned = $reportService->getUnplannedMaintenanceLog($teamId, $startDate, $endDate);

        $html = view('reports.unplanned-maintenance', [
            'unplanned' => $unplanned,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])->render();

        $html = $this->sanitizeUtf8($html);

        $pdf = Pdf::loadHTML($html);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'unplanned-maintenance-' . now()->format('Y-m-d') . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function exportCsv(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!$this->reportData) {
            Notification::make()
                ->title('No Report Data')
                ->body('Please generate a report first.')
                ->warning()
                ->send();
            return null;
        }

        $csv = $this->generateCsvContent($this->reportData);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'maintenance-report-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function generateCsvContent(array $report): string
    {
        $output = fopen('php://temp', 'r+');

        // Header
        fputcsv($output, ['Maintenance Report']);
        fputcsv($output, ['Period', $report['period']['start_date'] . ' to ' . $report['period']['end_date']]);
        fputcsv($output, []);

        // Summary Metrics
        fputcsv($output, ['Summary Metrics']);
        fputcsv($output, ['MTTR (hours)', $report['mttr']]);
        fputcsv($output, ['Total Cost', 'R' . $report['cost_analysis']['total_cost']]);
        fputcsv($output, ['Parts Cost', 'R' . $report['cost_analysis']['parts_cost']]);
        fputcsv($output, ['Labor Cost', 'R' . $report['cost_analysis']['labor_cost']]);
        fputcsv($output, ['Total Work Orders', $report['cost_analysis']['total_work_orders']]);
        fputcsv($output, []);

        // Equipment Performance
        fputcsv($output, ['Equipment Performance']);
        fputcsv($output, ['Equipment', 'Serial Number', 'Work Orders', 'Uptime %', 'Total Cost', 'Avg Cost']);
        foreach ($report['equipment_performance'] as $equipment) {
            fputcsv($output, [
                $equipment['equipment_name'],
                $equipment['serial_number'],
                $equipment['work_order_count'],
                $equipment['uptime_percentage'],
                'R' . $equipment['total_cost'],
                'R' . $equipment['average_cost'],
            ]);
        }
        fputcsv($output, []);

        // Technician Performance
        fputcsv($output, ['Technician Performance']);
        fputcsv($output, ['Technician', 'Assigned', 'Completed', 'In Progress', 'Completion Rate %', 'Avg Time (hrs)']);
        foreach ($report['technician_performance'] as $tech) {
            fputcsv($output, [
                $tech['technician_name'],
                $tech['total_assigned'],
                $tech['completed'],
                $tech['in_progress'],
                $tech['completion_rate'],
                $tech['average_completion_time_hours'],
            ]);
        }
        fputcsv($output, []);

        // Actionable Insights
        fputcsv($output, ['Actionable Insights']);
        fputcsv($output, ['Type', 'Category', 'Message', 'Recommendation']);
        foreach ($report['actionable_insights'] as $insight) {
            fputcsv($output, [
                $insight['type'],
                $insight['category'],
                $insight['message'],
                $insight['recommendation'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
