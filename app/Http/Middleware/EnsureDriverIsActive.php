<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un conducteur suspendu conserve son jeton mais perd l'accès aux ressources :
 * on renvoie 403 avec un motif affichable par l'application mobile.
 */
class EnsureDriverIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $driver = $request->user();

        if ($driver instanceof Driver && $driver->isSuspended()) {
            return new JsonResponse([
                'message' => __('api.suspended'),
                'reason' => $driver->suspension_reason ?? __('api.suspended'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
