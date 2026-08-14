<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Equipment\Pages;

use App\Filament\App\Resources\Equipment\EquipmentResource;
use App\Services\MaintenanceSchedulingService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEquipment extends EditRecord
{
    #[\Override]
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * If the hours reading just changed, immediately re-check this
     * equipment's hour-based maintenance schedules so a task that just
     * became overdue is pushed right away, rather than waiting for the
     * next daily batch check.
     */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged('current_hours')) {
            // Stamp when this reading was taken, so the overdue report can
            // show "as of the reading on [date]" for hour-based schedules.
            $this->record->update(['current_hours_recorded_at' => now()]);

            app(MaintenanceSchedulingService::class)
                ->checkSchedulesForEquipment($this->record);
        }
    }
}
