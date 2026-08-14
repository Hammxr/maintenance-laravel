<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\LineLeaders\Pages;

use App\Filament\App\Resources\LineLeaders\LineLeaderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLineLeaders extends ListRecords
{
    #[\Override]
    protected static string $resource = LineLeaderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
