<?php

namespace App\Services\Support;

use App\Enums\CampaignAudience;
use App\Enums\DriverStatus;
use App\Models\Campaign;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Traduit l'audience d'une campagne en requête sur `drivers`.
 *
 * Seul endroit qui sache lire le JSON de `segment` : la composition, le
 * comptage affiché avant l'envoi et la matérialisation des destinataires
 * passent tous par ici, pour qu'un agent ne puisse pas voir un nombre puis en
 * toucher un autre.
 */
class CampaignAudienceResolver
{
    /**
     * @return Builder<Driver>
     */
    public function query(Campaign $campaign): Builder
    {
        return $this->for($campaign->audience, (array) ($campaign->segment ?? []));
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return Builder<Driver>
     */
    public function for(CampaignAudience $audience, array $segment = []): Builder
    {
        $query = Driver::query();

        return match ($audience) {
            // Un conducteur supprimé n'est pas un destinataire ; `Driver`
            // porte `SoftDeletes`, le filtre est donc déjà implicite.
            CampaignAudience::All => $query,
            CampaignAudience::Individual => $query->whereIn(
                'id',
                array_values(array_filter((array) ($segment['driver_ids'] ?? []))),
            ),
            CampaignAudience::Segment => $this->applySegment($query, $segment),
        };
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    public function count(CampaignAudience $audience, array $segment = []): int
    {
        return $this->for($audience, $segment)->count();
    }

    /**
     * Prédicats du segment. Volontairement peu nombreux : une table de
     * segments nommés serait prématurée tant que le besoin tient en trois
     * filtres.
     *
     * @param  Builder<Driver>  $query
     * @param  array<string, mixed>  $segment
     * @return Builder<Driver>
     */
    private function applySegment(Builder $query, array $segment): Builder
    {
        $statuses = array_values(array_filter(
            (array) ($segment['status'] ?? []),
            fn (mixed $value): bool => DriverStatus::tryFrom((string) $value) !== null,
        ));

        return $query
            ->when($statuses !== [], fn (Builder $q): Builder => $q->whereIn('status', $statuses))
            ->when(
                array_key_exists('has_vehicle', $segment),
                fn (Builder $q): Builder => (bool) $segment['has_vehicle']
                    ? $q->whereHas('vehicle')
                    : $q->whereDoesntHave('vehicle'),
            )
            ->when(
                array_key_exists('has_yango_id', $segment),
                fn (Builder $q): Builder => (bool) $segment['has_yango_id']
                    ? $q->whereNotNull('yango_id')
                    : $q->whereNull('yango_id'),
            );
    }
}
