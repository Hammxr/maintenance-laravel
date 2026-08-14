<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\LineLeaders\Pages;

use App\Filament\App\Resources\LineLeaders\LineLeaderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLineLeader extends EditRecord
{
    #[\Override]
    protected static string $resource = LineLeaderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Equipment filed under a deleted line leader is not deleted with
            // it — the foreign key nulls out and the equipment becomes
            // unassigned.
            DeleteAction::make(),
        ];
    }
}
