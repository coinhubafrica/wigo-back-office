<?php

namespace App\Services\Recharge;

use App\Contracts\FleetClient;
use App\Contracts\WaveClient;
use App\Enums\TransactionProvider;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RechargeCredited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Recharge du solde Yango Pro par Wave.
 *
 * Deux temps que rien ne doit confondre : Wave encaisse, puis Yango crédite.
 * Entre les deux, l'argent a quitté le conducteur sans être arrivé sur son
 * solde — c'est l'état `to_review`, celui que le back-office rejoue.
 *
 * Invariant : un paiement ne crédite qu'une fois. Le webhook de Wave peut
 * arriver deux fois, un agent peut rejouer une ligne déjà réglée ; dans tous
 * les cas `credited` est terminal et la garde sous verrou coupe court.
 */
class RechargeService
{
    public function __construct(
        private WaveClient $wave,
        private FleetClient $fleet,
    ) {}

    /**
     * Ouvre une recharge et la session de paiement qui va avec.
     *
     * @throws ValidationException Montant hors plafonds, ou Wave indisponible.
     */
    public function initiate(Driver $driver, int $amount, ?string $idempotencyKey = null): Transaction
    {
        $this->assertWithinLimits($driver, $amount);

        $transaction = DB::transaction(function () use ($driver, $amount, $idempotencyKey): Transaction {
            return Transaction::query()->create([
                'driver_id' => $driver->getKey(),
                'type' => TransactionType::Recharge,
                'provider' => TransactionProvider::Wave,
                'status' => TransactionStatus::Initiated,
                'reference' => $this->nextReference(),
                'label' => 'Recharge YANGO PRO',
                'subtitle' => 'Paiement Wave',
                'amount' => $amount,
                'sign' => 1,
                'currency' => 'XOF',
                'idempotency_key' => $idempotencyKey,
                'initiated_at' => now(),
            ]);
        });

        // Hors transaction : un appel réseau ne doit pas tenir un verrou de
        // base ouvert le temps que Wave réponde.
        $session = $this->wave->createCheckoutSession($transaction);

        if ($session === null) {
            $transaction->update([
                'status' => TransactionStatus::Failed,
                'failure_reason' => 'Session de paiement refusée par Wave',
            ]);

            throw ValidationException::withMessages([
                'amount' => __('api.recharge.provider_unavailable'),
            ]);
        }

        $transaction->update([
            'external_reference' => $session->id,
            'checkout_url' => $session->launchUrl,
        ]);

        return $transaction->refresh();
    }

    /**
     * Règle une recharge sur notification de Wave.
     *
     * Appelée par la file, jamais par le contrôleur du webhook : Wave attend
     * un accusé, pas la réponse de Yango.
     */
    public function settleFromWebhook(string $clientReference, ?string $externalReference = null): void
    {
        /** @var Transaction|null $transaction */
        $transaction = DB::transaction(function () use ($clientReference, $externalReference): ?Transaction {
            $transaction = Transaction::query()
                ->where('reference', $clientReference)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                Log::error('Recharge : webhook sans transaction correspondante', [
                    'client_reference' => $clientReference,
                ]);

                return null;
            }

            // Garde d'idempotence : Wave rejoue ses webhooks. Une ligne déjà
            // créditée l'est définitivement — on ne recrédite jamais.
            if ($transaction->status === TransactionStatus::Credited) {
                Log::info('Recharge : webhook déjà traité, ignoré', [
                    'reference' => $clientReference,
                ]);

                return null;
            }

            if ($transaction->canTransitionTo(TransactionStatus::Paid)) {
                $transaction->update([
                    'status' => TransactionStatus::Paid,
                    'paid_at' => now(),
                    'external_reference' => $externalReference ?? $transaction->external_reference,
                ]);
            }

            return $transaction;
        });

        if ($transaction === null) {
            return;
        }

        $this->creditViaFleet($transaction->refresh());
    }

    /**
     * « ↻ Rejouer » : relance le crédit Yango d'une transaction encaissée mais
     * jamais portée au solde.
     *
     * @throws ValidationException Statut ne permettant pas le rejeu.
     */
    public function replay(Transaction $transaction, User $by): Transaction
    {
        if (! $transaction->status->isReplayable()) {
            throw ValidationException::withMessages([
                'status' => __('backoffice.recharges.not_replayable', [
                    'status' => $transaction->status->label(),
                ]),
            ]);
        }

        AuditLog::record(
            action: 'recharge.replayed',
            summary: "Rejeu de la transaction Wave {$transaction->reference}",
            subject: $transaction,
            by: $by,
            driver: $transaction->driver,
            context: ['amount' => $transaction->amount],
        );

        $this->creditViaFleet($transaction, $by);

        return $transaction->refresh();
    }

    /**
     * « ✓ Rechargé » : l'agent a crédité le compte à la main sur Yango, le
     * back-office ne fait que le constater.
     *
     * N'appelle donc pas Fleet — l'appeler recréditerait une seconde fois.
     *
     * @throws ValidationException Transition interdite depuis le statut courant.
     */
    public function markCreditedManually(Transaction $transaction, User $by, ?string $note = null): Transaction
    {
        if (! $transaction->canTransitionTo(TransactionStatus::Credited)) {
            throw ValidationException::withMessages([
                'status' => __('backoffice.recharges.not_creditable', [
                    'status' => $transaction->status->label(),
                ]),
            ]);
        }

        DB::transaction(function () use ($transaction, $by, $note): void {
            $transaction->update([
                'status' => TransactionStatus::Credited,
                'settled_at' => now(),
                'paid_at' => $transaction->paid_at ?? now(),
                'failure_reason' => null,
            ]);

            AuditLog::record(
                action: 'recharge.marked_credited',
                summary: "Recharge {$transaction->reference} marquée créditée à la main sur Yango",
                subject: $transaction,
                by: $by,
                driver: $transaction->driver,
                context: array_filter(['amount' => $transaction->amount, 'note' => $note]),
            );
        });

        $transaction->driver->notify(new RechargeCredited($transaction));

        return $transaction->refresh();
    }

    /**
     * Cumul déjà engagé aujourd'hui : réglé, encaissé, ou simplement en
     * attente de paiement — une session ouverte réserve son montant, sinon le
     * plafond se contournerait en ouvrant dix sessions d'un coup.
     */
    public function dailyTotalFor(Driver $driver): int
    {
        return (int) Transaction::query()
            ->recharges()
            ->where('driver_id', $driver->getKey())
            ->whereIn('status', [
                TransactionStatus::Initiated,
                TransactionStatus::Paid,
                TransactionStatus::Credited,
            ])
            ->whereBetween('initiated_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('amount');
    }

    /**
     * @return array{min: int, max: int, daily_cap: int, remaining_today: int}
     */
    public function limitsFor(Driver $driver): array
    {
        $cap = (int) config('wigo.recharge.daily_cap');

        return [
            'min' => (int) config('wigo.recharge.min_amount'),
            'max' => (int) config('wigo.recharge.max_amount'),
            'daily_cap' => $cap,
            'remaining_today' => max(0, $cap - $this->dailyTotalFor($driver)),
        ];
    }

    /**
     * Solde Yango en cache, rafraîchi auprès de Fleet s'il a vieilli.
     */
    public function balanceFor(Driver $driver): ?int
    {
        // Le conducteur authentifié vient du jeton Sanctum, qui ne charge pas
        // forcément toutes les colonnes : on relit celles dont on a besoin
        // plutôt que de supposer le modèle complet.
        if (! array_key_exists('balance_read_at', $driver->getAttributes())) {
            $driver = $driver->fresh() ?? $driver;
        }

        $ttl = (int) config('wigo.recharge.balance_ttl_minutes');
        $isFresh = $driver->balance_read_at !== null
            && $driver->balance_read_at->gt(now()->subMinutes($ttl));

        if ($isFresh) {
            return $driver->yango_balance;
        }

        $balance = $this->fleet->balanceFor($driver);

        if ($balance === null) {
            // Fleet muet : on rend la dernière valeur connue plutôt que rien.
            return $driver->yango_balance;
        }

        $driver->forceFill([
            'yango_balance' => $balance,
            'balance_read_at' => now(),
        ])->save();

        return $balance;
    }

    /**
     * Porte le montant au solde Yango, et tire les conséquences de l'échec.
     */
    private function creditViaFleet(Transaction $transaction, ?User $by = null): void
    {
        if ($transaction->status === TransactionStatus::Credited) {
            return;
        }

        $driver = $transaction->driver;
        $credited = $this->fleet->creditWallet($driver, $transaction->amount, $transaction->reference);

        if (! $credited) {
            $transaction->update([
                'status' => TransactionStatus::ToReview,
                'failure_reason' => 'Crédit du solde Yango refusé',
            ]);

            AuditLog::record(
                action: 'recharge.fleet_failed',
                summary: "Crédit Yango refusé pour la recharge {$transaction->reference}",
                subject: $transaction,
                by: $by,
                driver: $driver,
                context: ['amount' => $transaction->amount],
            );

            // Wave a encaissé, le conducteur n'a rien : une réconciliation
            // manuelle est nécessaire, elle doit être visible dans les logs.
            Log::error('Recharge : encaissée mais non créditée', [
                'reference' => $transaction->reference,
                'driver' => $driver->getKey(),
                'amount' => $transaction->amount,
            ]);

            return;
        }

        DB::transaction(function () use ($transaction, $driver, $by): void {
            $transaction->update([
                'status' => TransactionStatus::Credited,
                'settled_at' => now(),
                'failure_reason' => null,
            ]);

            $balance = $this->fleet->balanceFor($driver);

            if ($balance !== null) {
                $driver->forceFill([
                    'yango_balance' => $balance,
                    'balance_read_at' => now(),
                ])->save();
            }

            AuditLog::record(
                action: 'recharge.credited',
                summary: "Recharge {$transaction->reference} créditée sur le solde Yango",
                subject: $transaction,
                by: $by,
                driver: $driver,
                context: ['amount' => $transaction->amount],
            );
        });

        $driver->notify(new RechargeCredited($transaction->refresh()));
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinLimits(Driver $driver, int $amount): void
    {
        $min = (int) config('wigo.recharge.min_amount');
        $max = (int) config('wigo.recharge.max_amount');
        $cap = (int) config('wigo.recharge.daily_cap');

        if ($amount < $min) {
            throw ValidationException::withMessages([
                'amount' => __('api.recharge.amount_below_min', ['min' => $this->money($min)]),
            ]);
        }

        if ($amount > $max) {
            throw ValidationException::withMessages([
                'amount' => __('api.recharge.amount_above_max', ['max' => $this->money($max)]),
            ]);
        }

        if ($this->dailyTotalFor($driver) + $amount > $cap) {
            throw ValidationException::withMessages([
                'amount' => __('api.recharge.daily_cap_reached', ['cap' => $this->money($cap)]),
            ]);
        }
    }

    /**
     * « RCH-2026-0871 » : compteur annuel, calculé dans la transaction de la
     * recharge pour qu'il n'y ait ni trou ni doublon.
     */
    private function nextReference(): string
    {
        $year = now()->year;

        $count = Transaction::query()
            ->where('reference', 'like', "RCH-{$year}-%")
            ->lockForUpdate()
            ->count();

        return sprintf('RCH-%d-%04d', $year, $count + 1);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }
}
