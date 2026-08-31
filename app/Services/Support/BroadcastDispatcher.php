<?php

namespace App\Services\Support;

use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Driver;
use App\Notifications\BroadcastPublished;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Matérialise les destinataires d'une diffusion, puis les notifie.
 *
 * Une ligne par conducteur plutôt qu'un filtre rejoué à la lecture : sans
 * cela l'audience changerait sous les pieds du destinataire au gré de son
 * statut, et le taux d'ouverture n'aurait pas de dénominateur.
 *
 * Rejouable de bout en bout. L'unicité `(broadcast_id, driver_id)` absorbe
 * une reprise après échec, et la notification n'est envoyée qu'aux lignes
 * réellement insérées — reprendre un envoi à moitié fait ne renotifie donc
 * personne.
 */
class BroadcastDispatcher
{
    /** Taille des lots d'insertion. */
    private const CHUNK = 500;

    public function __construct(private BroadcastAudienceResolver $audience) {}

    public function dispatch(Broadcast $broadcast): Broadcast
    {
        $broadcast->forceFill(['status' => BroadcastStatus::Sending])->save();

        $inserted = $this->materialiseRecipients($broadcast);

        $broadcast->forceFill([
            'status' => BroadcastStatus::Sent,
            'sent_at' => $broadcast->sent_at ?? now(),
            'recipients_count' => $broadcast->recipients()->count(),
        ])->save();

        $this->notify($broadcast, $inserted);

        return $broadcast->refresh();
    }

    /**
     * Insère les destinataires par lots, et rend les identifiants des
     * conducteurs réellement ajoutés — ceux qui restent à notifier.
     *
     * @return list<string>
     */
    private function materialiseRecipients(Broadcast $broadcast): array
    {
        $inserted = [];

        $this->audience->query($broadcast)
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $drivers) use ($broadcast, &$inserted): void {
                $existing = $broadcast->recipients()
                    ->whereIn('driver_id', $drivers->pluck('id'))
                    ->pluck('driver_id')
                    ->all();

                $new = $drivers->pluck('id')->diff($existing)->values();

                if ($new->isEmpty()) {
                    return;
                }

                $now = now();

                DB::table('broadcast_recipients')->insertOrIgnore(
                    $new->map(fn (string $driverId): array => [
                        'id' => (string) Str::ulid(),
                        'broadcast_id' => $broadcast->getKey(),
                        'driver_id' => $driverId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );

                $inserted = [...$inserted, ...$new->all()];
            });

        return $inserted;
    }

    /**
     * Notifie les destinataires nouvellement ajoutés, par lots : la ligne en
     * base d'abord, le push ensuite — comme partout ailleurs.
     *
     * @param  list<string>  $driverIds
     */
    private function notify(Broadcast $broadcast, array $driverIds): void
    {
        if ($driverIds === []) {
            return;
        }

        Driver::query()
            ->whereIn('id', $driverIds)
            ->chunkById(self::CHUNK, function (Collection $drivers) use ($broadcast): void {
                foreach ($drivers as $driver) {
                    $driver->notify(new BroadcastPublished($broadcast));
                }
            });
    }
}
