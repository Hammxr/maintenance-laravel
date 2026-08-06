<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Tasks\Pages;

use App\Filament\App\Resources\Tasks\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTask extends CreateRecord
{
    #[\Override]
    protected static string $resource = TaskResource::class;

    /**
     * Tasks never had a team_id set on creation — there's no automatic
     * tenant-stamping happening here (this app sets it explicitly per
     * resource, same as CreateDocument does), so without this every task
     * silently ended up with team_id null and got excluded from anything
     * that filters reports by team, like the Unplanned Maintenance report.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['team_id'] = Auth::user()->currentTeam?->id;

        return $data;
    }
}
