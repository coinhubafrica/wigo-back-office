<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CnpsDeclarationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un versement RSTI déclaré. Plusieurs lignes peuvent couvrir le même mois.
 *
 * @property string $id
 * @property string $driver_id
 * @property string $period
 * @property int $declared_amount
 * @property CarbonImmutable $payment_date
 * @property string|null $proof_path
 * @property CarbonImmutable $declared_at
 * @property-read Driver $driver
 */
class CnpsDeclaration extends Model
{
    /** @use HasFactory<CnpsDeclarationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_amount' => 'integer',
            'payment_date' => 'date',
            'declared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Déclarations couvrant un mois donné, au format « 2026-08 ».
     *
     * @param  Builder<CnpsDeclaration>  $query
     */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }
}
