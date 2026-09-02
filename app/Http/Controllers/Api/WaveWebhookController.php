<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreditRechargeJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback de Wave Checkout — serveur à serveur, hors du contrat mobile.
 *
 * Volontairement dans `Api\` et non `Api\V1\` : le contrat mobile ne couvre
 * que `api/v1`, et l'application mobile n'a rien à faire de cette route.
 * `tests/Feature/Docs/ApiDocumentationTest.php` vérifie qu'elle n'y figure
 * pas.
 *
 * L'accusé part immédiatement, quel que soit le sort du crédit : un webhook
 * qui traîne est un webhook que Wave rejoue. Le travail réel se fait en file.
 */
class WaveWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $clientReference = $request->string('data.client_reference')->toString();

        if ($type === 'checkout.session.completed' && $clientReference !== '') {
            CreditRechargeJob::dispatch(
                $clientReference,
                $request->string('data.id')->toString() ?: null,
            );
        } else {
            // On accuse quand même réception : renvoyer une erreur ferait
            // rejouer Wave indéfiniment sur un événement qui ne nous concerne
            // pas.
            Log::info('Wave : événement ignoré', ['type' => $type]);
        }

        return new JsonResponse(['message' => 'ok']);
    }
}
