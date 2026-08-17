<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EquipmentCategories\Pages;

use App\Filament\App\Resources\EquipmentCategories\EquipmentCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEquipmentCategories extends ListRecords
{
    #[\Override]
    protected static string $resource = EquipmentCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
