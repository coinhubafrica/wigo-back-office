<?php

namespace App\Models;

use App\Enums\CnpsReferenceSetter;
use Carbon\CarbonImmutable;
use Database\Factories\CnpsReferenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Montant mensuel visé, historisé : une ligne par changement.
 *
 * @property string $id
 * @property string $driver_id
 * @property int $amount
 * @property CarbonImmutable $effective_from
 * @property CnpsReferenceSetter $set_by
 * @property CarbonImmutable $created_at
 * @property-read Driver $driver
 */
class CnpsReference extends Model
{
    /** @use HasFactory<CnpsReferenceFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_from' => 'date',
            'set_by' => CnpsReferenceSetter::class,
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
     * Classe du plus récent au plus ancien.
     *
     * `created_at` départage deux montants entrés le même jour : sans lui,
     * l'ordre serait celui de la table, et le dernier choix du conducteur ne
     * gagnerait pas de façon fiable.
     *
     * @param  Builder<CnpsReference>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('effective_from')->orderByDesc('created_at');
    }
}
