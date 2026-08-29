<?php

namespace App\Models;

use App\Enums\TransactionProvider;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement d'argent d'un conducteur. Table unique du MCD : une recharge Wave,
 * un paiement de commande et un versement de bonus sont la même ligne, à
 * `type` près.
 *
 * @property string $id
 * @property string $driver_id
 * @property TransactionType $type
 * @property TransactionProvider $provider
 * @property TransactionStatus $status
 * @property string $reference
 * @property string $label
 * @property string|null $subtitle
 * @property int $amount
 * @property int $sign
 * @property string $currency
 * @property string|null $external_reference
 * @property string|null $idempotency_key
 * @property string|null $checkout_url
 * @property CarbonImmutable $initiated_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $settled_at
 * @property string|null $receipt_code
 * @property string|null $receipt_url
 * @property string|null $failure_reason
 * @property-read Driver $driver
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'provider' => TransactionProvider::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'sign' => 'integer',
            'initiated_at' => 'datetime',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function canTransitionTo(TransactionStatus $status): bool
    {
        return $this->status->allows($status);
    }

    public function isRecharge(): bool
    {
        return $this->type === TransactionType::Recharge;
    }

    /**
     * Recharges seules — la table en portera d'autres types.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecharges(Builder $query): void
    {
        $query->where('type', TransactionType::Recharge);
    }

    /**
     * Recharges portées au solde Yango sur une journée donnée : la carte
     * « Encaissé aujourd'hui » du back-office.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSettledOn(Builder $query, CarbonImmutable $day): void
    {
        $query->where('status', TransactionStatus::Credited)
            ->whereBetween('settled_at', [$day->startOfDay(), $day->endOfDay()]);
    }
}
