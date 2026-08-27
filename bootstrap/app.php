<?php

use App\Enums\BackOfficeModule;
use App\Http\Middleware\EnsureDriverIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // API mobile
            'ability' => CheckForAnyAbility::class,
            'driver.active' => EnsureDriverIsActive::class,

            // Back-office : `permission` et `role` viennent de
            // spatie/laravel-permission (voir aussi les macros de route
            // `->permission(...)` et les directives Blade `@haspermission`).
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'user.active' => EnsureUserIsActive::class,
        ]);

        // L'écran de connexion du back-office est nommé `bo.login` : la
        // redirection par défaut de `auth` viserait une route `login` absente.
        $middleware->redirectGuestsTo(fn () => route('bo.login'));

        // La redirection par défaut de `guest` viserait `home` (`/`, qui
        // redirige lui-même vers `/login`) : boucle infinie pour un
        // utilisateur déjà connecté qui rouvre `/`.
        $middleware->redirectUsersTo(fn () => route(BackOfficeModule::Dashboard->route()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
