<?php

use App\Enums\BackOfficeModule;
use App\Http\Middleware\EnsureDriverIsActive;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'idempotency' => EnsureIdempotentRequest::class,

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

        // Enveloppe unique de l'API mobile : les erreurs sortent sous la même
        // forme que les succès (`{message, errors}` contre `{message, data}`),
        // pour que l'application n'ait qu'un seul format à lire. Le
        // back-office garde la gestion d'erreurs de Laravel : on rend `null`
        // hors `api/*` pour laisser passer.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            // Le limiteur de débit `otp` fournit déjà sa propre réponse JSON
            // (message français, en-têtes `Retry-After`) : elle remonte dans
            // une HttpResponseException et doit passer intacte.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    __('api.invalid_data'),
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    Response::HTTP_UNAUTHORIZED,
                    __('api.unauthenticated'),
                    [],
                ],
                $e instanceof AuthorizationException => [
                    Response::HTTP_FORBIDDEN,
                    $e->getMessage() !== '' ? $e->getMessage() : __('api.forbidden'),
                    [],
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    Response::HTTP_NOT_FOUND,
                    __('api.not_found'),
                    [],
                ],
                // Le limiteur `otp` définit son propre message en français :
                // on le conserve plutôt que de le remplacer.
                $e instanceof ThrottleRequestsException => [
                    Response::HTTP_TOO_MANY_REQUESTS,
                    $e->getMessage(),
                    [],
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage(),
                    [],
                ],
                // Message générique : ne jamais exposer l'exception en
                // production. En local, `APP_DEBUG` laisse Laravel rendre sa
                // page d'erreur détaillée avant d'arriver ici.
                default => [
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    __('api.server_error'),
                    [],
                ],
            };

            if ($message === '') {
                $message = Response::$statusTexts[$status] ?? __('api.server_error');
            }

            return new JsonResponse(
                $errors === [] ? ['message' => $message] : ['message' => $message, 'errors' => $errors],
                $status,
            );
        });
    })->create();
