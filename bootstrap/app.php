<?php

use App\Http\Middleware\AssignDefaultTeam;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TeamsPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Caddy terminates TLS in front of the app container, which is only
        // reachable over the internal Docker network — so every proxy that
        // can reach us is one we control, and trusting all of them is safe.
        // Without this Laravel sees plain HTTP and generates http:// URLs,
        // which breaks Livewire and Filament asset loading under HTTPS.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SecurityHeaders::class,
            AssignDefaultTeam::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'teams.permission' => TeamsPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
