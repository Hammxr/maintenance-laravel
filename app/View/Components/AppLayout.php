<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Backs the <x-app-layout> tag used by the Jetstream scaffold views.
 *
 * Jetstream ships this class in the application skeleton rather than the package,
 * and it went missing here — so `php artisan view:cache` (and therefore
 * `php artisan optimize`) aborted with "Unable to locate a class or view for
 * component [app-layout]" and no view could be precompiled.
 *
 * The four views that use the tag — profile/show, teams/create, teams/show and
 * api/index — are unreachable today: both panel providers set
 * Jetstream::$registersRoutes = false because Filament owns auth and profile.
 * This is kept anyway so the scaffold stays compilable, and so re-enabling those
 * routes does not require rediscovering why the layout tag resolves to nothing.
 */
class AppLayout extends Component
{
    public function render(): View
    {
        return view('layouts.app');
    }
}
