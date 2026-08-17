<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EquipmentCategories\Pages;

use App\Filament\App\Resources\EquipmentCategories\EquipmentCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEquipmentCategory extends CreateRecord
{
    #[\Override]
    protected static string $resource = EquipmentCategoryResource::class;
}
