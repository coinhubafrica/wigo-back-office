<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'une écriture idempotente : la clé envoyée par le mobile, l'empreinte
 * du corps, et la réponse rendue. Voir `EnsureIdempotentRequest`.
 *
 * @property string $id
 * @property string $key
 * @property string|null $driver_id
 * @property string $request_hash
 * @property int $response_status
 * @property array<string, mixed> $response_body
 * @property CarbonImmutable $expires_at
 */
class IdempotencyKey extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Clés encore opposables. Une clé périmée se comporte comme absente : la
     * requête repart normalement.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    public function matches(string $requestHash): bool
    {
        return hash_equals($this->request_hash, $requestHash);
    }
}
