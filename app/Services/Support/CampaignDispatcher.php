<?php

namespace App\Services\Support;

use App\Enums\CampaignStatus;
use App\Enums\SystemMessageEvent;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\MessageAttachment;
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
 * Une image éventuelle est téléversée **une fois** à la composition : chaque
 * message reçoit sa ligne `message_attachments` pointant le même fichier. Ce
 * sont des métadonnées, pas des copies, et elles rendent l'image lisible par
 * les chemins déjà en place — sans toucher au contrat de l'API.
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
                        attachments: $this->attachmentsFor($campaign),
                    );

                    $driver->notify(new CampaignPublished($campaign));
                }
            });
    }

    /**
     * Pièce jointe du message déposé chez un conducteur.
     *
     * Une ligne par destinataire, toutes sur le `path` téléversé une fois à la
     * composition : le disque ne reçoit pas cinq mille copies du même fichier.
     * Corollaire à ne pas perdre de vue — **supprimer ce fichier casse tous
     * les messages de l'envoi**, jamais un seul.
     *
     * La ligne naît sans `message_id` et `MessageService` la rattache dans la
     * foulée. La purge des orphelines ne s'en saisit pas : elle ne prend que
     * ce qui traîne depuis plus d'un jour.
     *
     * @return list<MessageAttachment>
     */
    private function attachmentsFor(Campaign $campaign): array
    {
        if (! $campaign->hasImage()) {
            return [];
        }

        return [MessageAttachment::query()->create($campaign->attachmentAttributes())];
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
