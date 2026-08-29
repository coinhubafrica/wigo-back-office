<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Trace d'une action sensible. En ajout seul : une ligne d'audit ne se modifie
 * pas et ne se supprime pas.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $driver_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $summary
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property CarbonImmutable $occurred_at
 * @property-read User|null $user
 * @property-read Driver|null $driver
 * @property-read Model|null $subject
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Point d'entrée unique du journal.
     *
     * `$summary` est la phrase affichée telle quelle dans le futur module
     * « Journal d'audit » : elle est figée maintenant, pour qu'elle reste
     * lisible même si le code ou les lignes visées changent ensuite.
     *
     * `$by` nul signifie « pas un agent » : webhook Wave, tâche planifiée.
     * L'adresse IP n'est capturée que dans un contexte HTTP.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $action,
        string $summary,
        ?Model $subject = null,
        ?User $by = null,
        ?Driver $driver = null,
        array $context = [],
    ): self {
        return self::query()->create([
            'user_id' => $by?->getKey(),
            'driver_id' => $driver?->getKey(),
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => $summary,
            'context' => $context === [] ? null : $context,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
