<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejeu des écritures du mobile.
 *
 * Le réseau coupe, l'application renvoie la même requête sans savoir si la
 * première est passée. Trois cas :
 *
 * - clé inconnue : la requête s'exécute, et la réponse est enregistrée si elle
 *   aboutit (2xx) ;
 * - clé connue, même corps : la réponse enregistrée est rendue telle quelle,
 *   le contrôleur n'est pas appelé — une seule commande, un seul décrément de
 *   stock, le même code de retrait ;
 * - clé connue, corps différent : 409, rien n'est créé. Mieux vaut un échec
 *   visible qu'une commande silencieusement fausse.
 *
 * Une clé expirée se comporte comme absente. Générique : les recharges Wave
 * réutiliseront ce middleware sans modification.
 */
class EnsureIdempotentRequest
{
    private const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || ! Str::isUuid($key)) {
            return new JsonResponse([
                'message' => __('api.invalid_data'),
                'errors' => ['Idempotency-Key' => [__('api.idempotency.key_required')]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hash = hash('sha256', $request->getContent());
        $existing = IdempotencyKey::query()->live()->where('key', $key)->first();

        if ($existing !== null) {
            if (! $existing->matches($hash)) {
                return new JsonResponse([
                    'message' => __('api.idempotency.key_reused'),
                    'errors' => ['Idempotency-Key' => [__('api.idempotency.key_reused')]],
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($existing->response_body, $existing->response_status);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $driver = $request->user();

            // `updateOrCreate` plutôt que `create` : une clé périmée a laissé
            // sa ligne, et `key` est unique. On réécrit la trace, on n'en
            // ajoute pas une seconde.
            IdempotencyKey::query()->updateOrCreate(['key' => $key], [
                'driver_id' => $driver instanceof Driver ? $driver->getKey() : null,
                'request_hash' => $hash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(assoc: true),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        }

        return $response;
    }
}
