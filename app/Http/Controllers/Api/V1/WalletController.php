<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRechargeRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Recharge\RechargeService;
use App\Support\Scramble\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    use ResolvesDriver;

    public function __construct(private RechargeService $recharges) {}

    /**
     * Portefeuille Yango Pro
     *
     * Solde courant et plafonds de recharge. Le solde vient du cache local,
     * rafraîchi auprès de Yango dès qu'il a vieilli ; il est nul tant qu'aucune
     * lecture n'a abouti (conducteur pas encore rapproché du parc).
     *
     * `remaining_today` dit ce qu'il reste à engager aujourd'hui : les sessions
     * ouvertes mais non payées comptent déjà.
     *
     * @response array{message: string, data: array{balance: int|null, balance_read_at: string|null, currency: string, limits: array{min: int, max: int, daily_cap: int, remaining_today: int}}}
     */
    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $balance = $this->recharges->balanceFor($driver);

        return $this->okApiResponse([
            'balance' => $balance,
            'balance_read_at' => $driver->refresh()->balance_read_at?->toIso8601String(),
            'currency' => 'XOF',
            'limits' => $this->recharges->limitsFor($driver),
        ]);
    }

    /**
     * Historique des recharges
     *
     * De la plus récente à la plus ancienne. Pagination par curseur :
     * `meta.next_cursor` porte le curseur suivant (`null` sur la dernière
     * page), à renvoyer dans `?cursor=`. `per_page` est plafonné à 50.
     */
    #[ApiResponse(TransactionResource::class, collection: true, paginated: true)]
    public function recharges(Request $request): JsonResponse
    {
        $recharges = $this->driver($request)
            ->transactions()
            ->recharges()
            ->reorder()
            ->orderByDesc('initiated_at')
            // `initiated_at` n'est pas unique : le curseur a besoin d'une clé
            // de départage stable, sinon des lignes peuvent être sautées.
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request));

        return $this->okApiResponse(TransactionResource::collection($recharges));
    }

    /**
     * Lancer une recharge
     *
     * Ouvre une session de paiement Wave et rend `wave_launch_url`, que
     * l'application ouvre pour que le conducteur paie. La recharge n'est
     * créditée qu'au retour du webhook de Wave — jamais à cette étape.
     *
     * L'en-tête `Idempotency-Key` (UUID) est obligatoire : renvoyer deux fois
     * la même requête ne crée qu'une recharge.
     */
    #[ApiResponse(TransactionResource::class)]
    public function storeRecharge(StoreRechargeRequest $request): JsonResponse
    {
        $recharge = $this->recharges->initiate(
            $this->driver($request),
            (int) $request->validated('amount'),
            $request->header('Idempotency-Key'),
        );

        return $this->createdApiResponse(
            new TransactionResource($recharge),
            __('api.recharge.initiated'),
        );
    }

    /**
     * État d'une recharge
     *
     * La recharge d'un autre conducteur répond 404 : rien ne fuit d'un compte
     * à l'autre.
     */
    #[ApiResponse(TransactionResource::class)]
    public function showRecharge(Request $request, Transaction $transaction): JsonResponse
    {
        abort_unless(
            $transaction->driver_id === $this->driver($request)->getKey() && $transaction->isRecharge(),
            404,
        );

        return $this->okApiResponse(new TransactionResource($transaction));
    }

    /**
     * Taille de page demandée, bornée à 50 comme annoncé au contrat.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 50));
    }
}
