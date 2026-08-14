<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkOrders\WorkOrderResource\Pages;

use App\Filament\App\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkOrder extends CreateRecord
{
    #[\Override]
    protected static string $resource = WorkOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['submitted_at'] = now();

        // A technician creating their own work order (e.g. reporting a fault
        // they've spotted) should automatically be the assignee, otherwise
        // it would immediately vanish from their own scoped view.
        if (empty($data['assigned_to']) && auth()->user()?->hasRole('technician')) {
            $data['assigned_to'] = auth()->id();
        }

        return $data;
    }
}
