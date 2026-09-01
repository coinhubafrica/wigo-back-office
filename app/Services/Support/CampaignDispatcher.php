<?php

namespace App\Services\Support;

use App\Enums\CampaignStatus;
use App\Enums\SystemMessageEvent;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Driver;
use App\Notifications\CampaignPublished;
use Illuminate\Support\Collection;

/**
 * Envoie un message groupé : le dépose dans la conversation de chaque
 * conducteur visé, puis les notifie.
 *
 * Le conducteur le lit là où il lit déjà le support — un message système dans
 * son fil — et peut y répondre sur place. La réponse repart alors en tri comme
 * n'importe quel sujet nouveau, en gardant le lien vers l'envoi d'origine.
 *
 * Pas de table de destinataires : les messages déposés font foi. Ils disent
 * qui a reçu, et leur `read_at` dit qui a lu.
 *
 * Rejouable de bout en bout : un conducteur qui a déjà reçu l'envoi est
 * ignoré, donc reprendre un envoi à moitié fait ne dépose ni ne notifie deux
 * fois.
 */
class CampaignDispatcher
{
    /** Taille des lots. */
    private const CHUNK = 500;

    public function __construct(
        private CampaignAudienceResolver $audience,
        private ConversationResolver $conversations,
        private MessageService $messages,
    ) {}

    public function dispatch(Campaign $campaign): Campaign
    {
        $campaign->forceFill(['status' => CampaignStatus::Sending])->save();

        $this->deliver($campaign);

        $campaign->forceFill([
            'status' => CampaignStatus::Sent,
            'sent_at' => $campaign->sent_at ?? now(),
            'recipients_count' => $campaign->messages()->count(),
        ])->save();

        return $campaign->refresh();
    }

    /**
     * Dépose le message dans chaque conversation, par lots.
     */
    private function deliver(Campaign $campaign): void
    {
        $this->audience->query($campaign)
            ->chunkById(self::CHUNK, function (Collection $drivers) use ($campaign): void {
                // Ceux qui l'ont déjà reçu : une reprise ne redépose rien.
                $already = $campaign->messages()
                    ->whereIn('conversation_id', $this->conversationIdsOf($drivers))
                    ->pluck('conversation_id')
                    ->all();

                foreach ($drivers as $driver) {
                    $conversation = $this->conversations->for($driver);

                    if (in_array($conversation->getKey(), $already, strict: true)) {
                        continue;
                    }

                    $this->messages->writeSystemMessage(
                        $conversation,
                        SystemMessageEvent::CampaignMessage,
                        ['body' => $campaign->body, 'title' => $campaign->title],
                        campaign: $campaign,
                    );

                    $driver->notify(new CampaignPublished($campaign));
                }
            });
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     * @return list<string>
     */
    private function conversationIdsOf(Collection $drivers): array
    {
        return array_values(
            Conversation::query()
                ->whereIn('driver_id', $drivers->pluck('id'))
                ->pluck('id')
                ->all(),
        );
    }
}
