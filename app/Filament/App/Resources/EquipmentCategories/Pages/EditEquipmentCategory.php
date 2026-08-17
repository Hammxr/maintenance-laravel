<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EquipmentCategories\Pages;

use App\Filament\App\Resources\EquipmentCategories\EquipmentCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEquipmentCategory extends EditRecord
{
    #[\Override]
    protected static string $resource = EquipmentCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Equipment filed under a deleted category is not deleted with it —
            // the foreign key nulls out and the equipment becomes uncategorised.
            DeleteAction::make(),
        ];
    }
}
