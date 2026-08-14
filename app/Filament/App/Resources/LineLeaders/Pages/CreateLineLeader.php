<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\LineLeaders\Pages;

use App\Filament\App\Resources\LineLeaders\LineLeaderResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateLineLeader extends CreateRecord
{
    #[\Override]
    protected static string $resource = LineLeaderResource::class;

    /**
     * Stamp the tenant by hand. Filament does not associate the panel tenant
     * with new records in this app — equipment created through the UI has a
     * null team_id for the same reason — and a line leader without one is
     * invisible to the tenant-scoped dropdown and filter on the equipment
     * list, so it would be created and then never usable.
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['team_id'] ??= Filament::getTenant()?->id;

        return $data;
    }
}
