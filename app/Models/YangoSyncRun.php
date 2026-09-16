<?php

namespace App\Models;

use App\Enums\YangoSyncRunKind;
use App\Enums\YangoSyncRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Trace d'une passe de rattrapage : ce qui a été demandé, et où cela en est.
 *
 * Écrite par `Livewire\YangoSync\Index` à la mise en file, puis par le job
 * lui-même — d'où les trois transitions publiques ci-dessous plutôt qu'une
 * écriture libre depuis les appelants : un état se pose toujours avec son
 * horodatage, et jamais l'un sans l'autre.
 *
 * @property string $id
 * @property CarbonImmutable $day
 * @property YangoSyncRunKind $kind
 * @property YangoSyncRunStatus $status
 * @property ?string $user_id
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?array<string, mixed> $summary
 * @property ?string $error
 * @property-read ?User $user
 */
class YangoSyncRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Format explicite, comme `DriverDailyActivity::$casts` : sans lui
            // le cast `date` écrit « 2026-09-12 00:00:00 » dans une colonne
            // `date`, et la clé de recherche ne retrouve pas la ligne qu'elle
            // vient d'écrire.
            'day' => 'date:Y-m-d',
            'kind' => YangoSyncRunKind::class,
            'status' => YangoSyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ouvre — ou rouvre — la trace d'une journée et d'une nature.
     *
     * `updateOrCreate` sur la maille du job : relancer une journée réécrit sa
     * trace au lieu d'en empiler une seconde. L'échec précédent est effacé,
     * c'est voulu — ce qu'on veut lire, c'est où en est la demande courante.
     */
    public static function queueFor(YangoSyncRunKind $kind, string $day, ?string $userId): self
    {
        return static::query()->updateOrCreate(
            ['day' => $day, 'kind' => $kind],
            [
                'status' => YangoSyncRunStatus::Queued,
                'user_id' => $userId,
                'started_at' => null,
                'finished_at' => null,
                'summary' => null,
                'error' => null,
            ],
        );
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => YangoSyncRunStatus::Running,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markFinished(array $summary = []): void
    {
        $this->update([
            'status' => YangoSyncRunStatus::Finished,
            'finished_at' => Carbon::now(),
            'summary' => $summary,
        ]);
    }

    /**
     * Vrai quand la passe s'est arrêtée sur un refus : la vue montre alors le
     * message plutôt que le résumé.
     */
    public function hasFailed(): bool
    {
        return $this->status === YangoSyncRunStatus::Failed;
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => YangoSyncRunStatus::Failed,
            'finished_at' => Carbon::now(),
            // Le message brut d'une exception Yango porte volontiers une URL
            // signée : on tronque, et on ne garde que de quoi reconnaître la
            // panne.
            'error' => mb_substr($error, 0, 500),
        ]);
    }
}
