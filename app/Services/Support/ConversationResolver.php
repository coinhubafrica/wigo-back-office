<?php

namespace App\Services\Support;

use App\Models\Conversation;
use App\Models\Driver;
use Illuminate\Support\Facades\DB;

/**
 * Donne — en la créant au besoin — l'unique conversation d'un conducteur.
 *
 * Il n'y en a jamais qu'une, pour toujours : c'est ce que le conducteur voit,
 * un fil continu. Les tickets se découpent dedans sans jamais la refermer.
 */
class ConversationResolver
{
    /**
     * Deux appareils qui écrivent en même temps ne doivent pas créer deux
     * fils : la contrainte d'unicité sur `driver_id` l'interdit en base, et
     * `firstOrCreate` la rattrape ici.
     */
    public function for(Driver $driver): Conversation
    {
        return DB::transaction(fn (): Conversation => Conversation::query()
            ->firstOrCreate(
                ['driver_id' => $driver->getKey()],
                // Les valeurs par défaut sont posées explicitement : celles de
                // la table ne redescendent pas sur l'instance fraîchement
                // créée, et le contrat mobile annonce un entier, pas `null`.
                ['driver_unread_count' => 0],
            ));
    }
}
