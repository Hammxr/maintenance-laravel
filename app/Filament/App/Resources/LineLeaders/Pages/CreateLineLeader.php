<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\LineLeaders\Pages;

use App\Filament\App\Resources\LineLeaders\LineLeaderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLineLeader extends CreateRecord
{
    #[\Override]
    protected static string $resource = LineLeaderResource::class;
}
