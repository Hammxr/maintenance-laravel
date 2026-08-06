<?php

namespace App\Filament\App\Pages;

use App\Models\Equipment;
use App\Models\MaintenanceSchedule;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ChangeLogReport extends Page
{
    #[\Override]
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    #[\Override]
    protected string $view = 'filament.app.pages.change-log-report';

    #[\Override]
    protected static string | \UnitEnum | null $navigationGroup = 'Reports';

    #[\Override]
    protected static ?string $navigationLabel = 'Change Log';

    #[\Override]
    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public ?array $entries = null;

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
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
                            ->default(now()->startOfMonth()),
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

        $this->entries = $this->buildEntries($startDate, $endDate);

        Notification::make()
            ->title('Change Log Generated')
            ->body(count($this->entries) . ' change' . (count($this->entries) === 1 ? '' : 's') . ' found for this period.')
            ->success()
            ->send();
    }

    public function exportPdf(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        ini_set('memory_limit', '1024M');

        if ($this->entries === null) {
            Notification::make()
                ->title('No Report Data')
                ->body('Please generate a report first.')
                ->warning()
                ->send();
            return null;
        }

        $data = $this->form->getState();
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = Carbon::parse($data['end_date'])->endOfDay();

        $html = view('reports.change-log', [
            'entries' => $this->entries,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])->render();

        $html = $this->sanitizeUtf8($html);

        $pdf = Pdf::loadHTML($html);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'change-log-' . now()->format('Y-m-d') . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Same sanitation approach used by the other report exports — dompdf
     * throws a hard error on any malformed UTF-8 byte sequence, most often
     * from text pasted in from Word/Outlook under the wrong encoding.
     */
    protected function sanitizeUtf8(string $html): string
    {
        if (preg_match('//u', $html) === 1) {
            return $html;
        }

        $converted = @mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        if ($converted !== false && preg_match('//u', $converted) === 1) {
            return $converted;
        }

        $stripped = @iconv('UTF-8', 'UTF-8//IGNORE', $html);
        if ($stripped !== false && preg_match('//u', $stripped) === 1) {
            return $stripped;
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $html);
    }

    /**
     * Pull every logged Equipment/MaintenanceSchedule change in the period
     * (from anyone — admin or technician) and turn each Activity record
     * into a flat, report-friendly array: who did it, what kind of record,
     * which record, what kind of change, and a readable field-by-field
     * diff.
     *
     * @return array<int, array{date: \Carbon\Carbon, causer_name: string, subject_type: string, subject_name: string, event: string, changes: array<int, string>}>
     */
    protected function buildEntries(Carbon $startDate, Carbon $endDate): array
    {
        $activities = Activity::query()
            ->whereIn('log_name', ['equipment', 'maintenance_schedule'])
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->with(['causer', 'subject'])
            ->orderByDesc('created_at')
            ->get();

        return $activities->map(function (Activity $activity) {
            return [
                // Stored as a plain string (not a raw Carbon instance) so it
                // round-trips cleanly through Livewire's public-property
                // state between the "Generate Report" and "Export PDF"
                // requests, the same way every other report page here does.
                'date' => $activity->created_at?->toDateTimeString(),
                'causer_name' => $activity->causer?->name ?? 'System',
                'subject_type' => match ($activity->subject_type) {
                    Equipment::class => 'Equipment',
                    MaintenanceSchedule::class => 'Maintenance Schedule',
                    default => class_basename($activity->subject_type ?? ''),
                },
                'subject_name' => $activity->subject?->name ?? '(record no longer exists)',
                'event' => ucfirst($activity->event ?? $activity->description),
                'changes' => $this->formatChanges($activity),
            ];
        })->values()->all();
    }

    /**
     * Turn an activity's raw attribute_changes payload into readable
     * "Field: old → new" lines. For a create, there's no "old" to compare
     * against, so it just lists the starting values; for a delete, only the
     * "old" side exists.
     *
     * @return array<int, string>
     */
    protected function formatChanges(Activity $activity): array
    {
        $changes = $activity->attribute_changes ?? collect();
        $attributes = collect($changes['attributes'] ?? []);
        $old = collect($changes['old'] ?? []);

        if ($attributes->isEmpty() && $old->isNotEmpty()) {
            // Deleted — nothing new to compare against, just show what it was.
            return $old->map(fn ($value, $key) => str($key)->headline() . ': ' . $this->formatValue($value))->values()->all();
        }

        return $attributes->map(function ($value, $key) use ($old) {
            $label = str($key)->headline();

            if ($old->has($key) && $old->get($key) !== $value) {
                return $label . ': ' . $this->formatValue($old->get($key)) . " \u{2192} " . $this->formatValue($value);
            }

            return $label . ': ' . $this->formatValue($value);
        })->values()->all();
    }

    protected function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }
}
