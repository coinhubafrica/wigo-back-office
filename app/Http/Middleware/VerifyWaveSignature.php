<?php

namespace App\Http\Middleware;

use App\Contracts\WaveClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie le callback de Wave.
 *
 * Le webhook n'a ni jeton ni session : la signature EST l'authentification.
 * Sans elle, n'importe qui pourrait déclarer un paiement et faire créditer un
 * solde. Le HMAC porte sur le corps BRUT — le relire après décodage JSON
 * donnerait un autre condensat.
 */
class VerifyWaveSignature
{
    private const HEADER = 'Wave-Signature';

    public function __construct(private WaveClient $wave) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header(self::HEADER);

        if (! $this->wave->verifySignature($request->getContent(), $signature)) {
            Log::warning('Wave : signature de webhook refusée', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            return new JsonResponse(
                ['message' => __('api.recharge.invalid_signature')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
