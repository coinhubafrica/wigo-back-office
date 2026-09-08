<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un conducteur radié chez Yango conserve son jeton mais n'écrit plus rien :
 * on renvoie 403 avec un message affichable par l'application mobile.
 *
 * Seul `fired` ferme la porte. `not_working` est un état ordinaire — un
 * conducteur qui ne roule pas aujourd'hui garde sa boutique, ses recharges et
 * ses cotisations.
 */
class EnsureDriverIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $driver = $request->user();

        if ($driver instanceof Driver && $driver->cannotWriteFromMobile()) {
            return new JsonResponse([
                'message' => __('api.fired'),
                'reason' => __('api.fired'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
