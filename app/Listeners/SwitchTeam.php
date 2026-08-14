<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Filament\Events\TenantSet;

class SwitchTeam
{
    /**
     * Keep the user's current team in step with the Filament tenant.
     *
     * The tenant comes from the URL, while the API controllers and Jetstream
     * read `current_team_id`. Without this they drift apart and writes get
     * stamped with the team the user last visited rather than this one.
     */
    public function handle(TenantSet $event): void
    {
        $user = $event->getUser();

        if (! $user instanceof User) {
            return;
        }

        $team = $event->getTenant();

        if ($user->current_team_id === $team->getKey()) {
            return;
        }

        $user->switchTeam($team);
    }
}
