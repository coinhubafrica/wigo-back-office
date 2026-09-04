<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreditRechargeJob;
use App\Settings\WaveAccount;
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
 *
 * Deux comptes Wave y aboutissent, distingués par le segment d'URL (déjà
 * authentifié par `VerifyWaveSignature`). Seule la recharge crédite un
 * portefeuille Yango ; la boutique n'est pas encore branchée, et un paiement
 * qui lui parviendrait est journalisé plutôt que porté au mauvais flux.
 */
class WaveWebhookController extends Controller
{
    public function __invoke(Request $request, string $account): JsonResponse
    {
        $type = $request->string('type')->toString();
        $clientReference = $request->string('data.client_reference')->toString();
        $isCompleted = $type === 'checkout.session.completed' && $clientReference !== '';

        if ($isCompleted && WaveAccount::from($account) === WaveAccount::Topup) {
            CreditRechargeJob::dispatch(
                $clientReference,
                $request->string('data.id')->toString() ?: null,
            );
        } elseif ($isCompleted) {
            // Compte boutique : l'encaissement des commandes n'est pas encore
            // branché. On accuse réception pour que Wave ne rejoue pas, et on
            // trace le règlement en attendant que le flux existe.
            Log::warning('Wave : paiement boutique reçu, aucun traitement branché', [
                'reference' => $clientReference,
                'account' => $account,
            ]);
        } else {
            // On accuse quand même réception : renvoyer une erreur ferait
            // rejouer Wave indéfiniment sur un événement qui ne nous concerne
            // pas.
            Log::info('Wave : événement ignoré', ['type' => $type, 'account' => $account]);
        }

        return new JsonResponse(['message' => 'ok']);
    }
}
