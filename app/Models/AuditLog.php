<?php

namespace App\Models;

use App\Enums\AuditAction;
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
 * @property string|null $user_id
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
     * Le geste de cette ligne, s'il est encore au catalogue.
     *
     * `null` quand le slug n'y figure pas : la table est en ajout seul et
     * jamais purgée, donc une ligne écrite par un code retiré depuis doit
     * rester affichable. L'écran retombe alors sur le slug brut plutôt que de
     * faire tomber la page — c'est pourquoi la lecture passe par `tryFrom()`
     * et que `record()` garde une chaîne en paramètre.
     */
    public function actionEnum(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action);
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
