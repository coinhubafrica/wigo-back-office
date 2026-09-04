<?php

namespace App\Services\Support;

use App\Enums\AuditAction;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\SystemMessageEvent;
use App\Jobs\DeliverCampaignChunkJob;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Driver;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\CampaignPublished;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoie un message groupé : le dépose dans la conversation de chaque
 * conducteur visé, puis les notifie.
 *
 * Le conducteur le lit là où il lit déjà le support — un message système dans
 * son fil — et peut y répondre sur place. La réponse repart alors en tri comme
 * n'importe quel sujet nouveau, en gardant le lien vers l'envoi d'origine.
 *
 * L'envoi se fait en trois temps, et cet ordre est ce qui le rend sûr :
 *
 * 1. **Matérialiser** l'audience dans `campaign_recipients` (`insertOrIgnore`,
 *    par lots). L'audience est figée là, une fois pour toutes.
 * 2. **Essaimer** un job par lot de destinataires, sous un `Bus::batch`.
 * 3. **Conclure** (`settle()`) quand le lot est terminé, quoi qu'il arrive.
 *
 * Une image éventuelle est téléversée **une fois** à la composition : chaque
 * message reçoit sa ligne `message_attachments` pointant le même fichier. Ce
 * sont des métadonnées, pas des copies, et elles rendent l'image lisible par
 * les chemins déjà en place — sans toucher au contrat de l'API.
 */
class CampaignDispatcher
{
    /** Taille des lots de matérialisation. */
    private const CHUNK = 500;

    /** Nombre de destinataires confiés à un même job de remise. */
    private const DELIVERY_CHUNK = 200;

    /** Longueur retenue du message d'erreur affiché à l'agent. */
    private const ERROR_LENGTH = 500;

    public function __construct(
        private CampaignAudienceResolver $audience,
        private ConversationResolver $conversations,
        private MessageService $messages,
    ) {}

    /**
     * Lance l'envoi : fige l'audience, puis essaime les remises.
     *
     * En file `sync` — donc dans les tests — le lot s'exécute en ligne et
     * `settle()` est appelé avant le retour : la campagne rendue est déjà dans
     * son état final.
     */
    public function dispatch(Campaign $campaign): Campaign
    {
        $this->materialise($campaign);

        $campaign->forceFill([
            'status' => CampaignStatus::Sending,
            // Le nombre de visés, et non de remis : c'est celui que l'agent a
            // confirmé avant de lancer l'envoi.
            'recipients_count' => $campaign->targetedCount(),
        ])->save();

        $this->fanOut($campaign);

        return $campaign->refresh();
    }

    /**
     * Fige l'audience : une ligne par conducteur visé.
     *
     * `insertOrIgnore` adossé à `UNIQUE (campaign_id, driver_id)` : rejouer la
     * matérialisation — reprise après échec, worker relancé par le délai de
     * `retry_after` — n'ajoute jamais personne deux fois.
     */
    public function materialise(Campaign $campaign): void
    {
        $this->audience->query($campaign)
            ->chunkById(self::CHUNK, function (Collection $drivers) use ($campaign): void {
                CampaignRecipient::query()->insertOrIgnore(
                    $drivers->map(fn (Driver $driver): array => [
                        'id' => (string) Str::ulid(),
                        'campaign_id' => $campaign->getKey(),
                        'driver_id' => $driver->getKey(),
                        'status' => CampaignRecipientStatus::Pending->value,
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            });
    }

    /**
     * Essaime les remises restantes, par lots, sous un `Bus::batch`.
     *
     * `allowFailures()` est indispensable : sans lui, le premier lot en échec
     * annulerait tous les suivants et la moitié du parc ne recevrait rien sans
     * que rien ne le dise.
     *
     * Le rappel de fin ne capture que l'identifiant : les rappels d'un lot sont
     * sérialisés puis exécutés plus tard, `$this` n'y a pas sa place.
     */
    private function fanOut(Campaign $campaign): void
    {
        $chunks = $campaign->recipients()
            ->whereNull('claimed_at')
            ->where('status', '!=', CampaignRecipientStatus::Sent)
            ->pluck('id')
            ->chunk(self::DELIVERY_CHUNK)
            ->map(fn (Collection $ids): DeliverCampaignChunkJob => new DeliverCampaignChunkJob(
                $campaign->getKey(),
                $ids->values()->all(),
            ));

        if ($chunks->isEmpty()) {
            $this->settle($campaign);

            return;
        }

        $campaignId = $campaign->getKey();

        Bus::batch($chunks->all())
            ->name("campaign:{$campaignId}")
            ->allowFailures()
            ->finally(function () use ($campaignId): void {
                $campaign = Campaign::query()->find($campaignId);

                if ($campaign !== null) {
                    app(CampaignDispatcher::class)->settle($campaign);
                }
            })
            ->onQueue('campaigns')
            ->dispatch();
    }

    /**
     * Remet un lot de destinataires. Appelée par le job de lot.
     *
     * @param  list<string>  $recipientIds
     */
    public function deliverChunk(Campaign $campaign, array $recipientIds): void
    {
        $recipients = CampaignRecipient::query()
            ->whereKey($recipientIds)
            ->with('driver')
            ->get();

        foreach ($recipients as $recipient) {
            $this->deliverTo($campaign, $recipient);
        }
    }

    /**
     * Remet l'envoi à un destinataire, si personne ne l'a déjà pris.
     *
     * Toute exception est **attrapée ici** : un conducteur dont la remise casse
     * ne doit pas priver les cent quatre-vingt-dix-neuf autres de son lot. Et
     * laisser l'exception remonter ferait échouer le job, donc — en file
     * `sync` — remonterait jusqu'à l'appelant sans que le lot se conclue.
     */
    private function deliverTo(Campaign $campaign, CampaignRecipient $recipient): void
    {
        if (! $this->claim($recipient)) {
            return;
        }

        $driver = $recipient->driver;

        if ($driver === null) {
            $this->markFailed($recipient, 'conducteur introuvable');

            return;
        }

        try {
            $conversation = $this->conversations->for($driver);

            $message = $this->messages->writeSystemMessage(
                $conversation,
                SystemMessageEvent::CampaignMessage,
                ['body' => $campaign->body, 'title' => $campaign->title],
                campaign: $campaign,
                attachments: $this->attachmentsFor($campaign),
            );

            $recipient->forceFill([
                'status' => CampaignRecipientStatus::Sent,
                'message_id' => $message->getKey(),
                'delivered_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($recipient, $exception->getMessage());

            return;
        }

        /*
        | Hors du `try`, et après avoir constaté la remise : un push raté n'est
        | pas un échec de remise. Le message est dans le fil du conducteur,
        | c'est lui le produit ; le push n'est qu'un réveil, et `PushSender`
        | rend `false` sans jamais lever.
        */
        $driver->notify(new CampaignPublished($campaign));
    }

    /**
     * Réserve un destinataire. Rend `false` si un autre worker l'a déjà pris.
     *
     * Un `UPDATE ... WHERE claimed_at IS NULL` d'une seule instruction est
     * atomique : exactement un worker en ressort avec une ligne modifiée, tous
     * les autres avec zéro. C'est ce qui rend le double envoi impossible, et
     * c'est pourquoi la réservation doit précéder l'écriture du message —
     * l'ancienne garde lisait puis écrivait, laissant deux workers passer.
     */
    private function claim(CampaignRecipient $recipient): bool
    {
        $claimed = CampaignRecipient::query()
            ->whereKey($recipient->getKey())
            ->whereNull('claimed_at')
            ->update([
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $recipient->refresh();

        return true;
    }

    /**
     * `claimed_at` est relâché : la ligne redevient réservable, donc rejouable.
     */
    private function markFailed(CampaignRecipient $recipient, string $error): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipientStatus::Failed,
            'claimed_at' => null,
            'error' => Str::limit($error, self::ERROR_LENGTH),
        ])->save();
    }

    /**
     * Sort la campagne de `Sending`, une fois le lot terminé.
     *
     * `Failed` seulement si **rien** n'est parti : une campagne remise à 4 999
     * conducteurs sur 5 000 est envoyée, et ses échecs se lisent sur sa page.
     * La marquer échouée serait un contresens.
     *
     * Idempotente : le rappel de fin peut tomber deux fois, et une reprise
     * manuelle doit pouvoir la rappeler.
     */
    public function settle(Campaign $campaign): Campaign
    {
        $delivered = $campaign->deliveredCount();

        $campaign->forceFill([
            'status' => $delivered > 0 || $campaign->targetedCount() === 0
                ? CampaignStatus::Sent
                : CampaignStatus::Failed,
            'sent_at' => $campaign->sent_at ?? now(),
        ])->save();

        return $campaign->refresh();
    }

    /**
     * Rejoue un destinataire en échec.
     *
     * Journalisé avant l'acte : une remise atteint un conducteur réel, et il
     * doit rester possible de dire qui l'a relancée.
     */
    public function replayRecipient(CampaignRecipient $recipient, User $by): void
    {
        AuditLog::record(
            action: AuditAction::CampaignRecipientsReplayed->value,
            summary: "{$by->fullName()} a rejoué une remise en échec de la campagne « {$recipient->campaign->title} ».",
            subject: $recipient->campaign,
            by: $by,
            driver: $recipient->driver,
            context: ['recipients' => 1],
        );

        $this->reopen(CampaignRecipient::query()->whereKey($recipient->getKey()));

        $this->fanOut($recipient->campaign);
    }

    /**
     * Rejoue tous les échecs d'une campagne.
     *
     * Repasse par le lot plutôt que par une boucle synchrone : trois mille
     * échecs ne doivent pas tenir dans le temps d'une requête HTTP.
     *
     * @return int Nombre de remises relancées.
     */
    public function replayFailures(Campaign $campaign, User $by): int
    {
        $count = $campaign->failedCount();

        if ($count === 0) {
            return 0;
        }

        AuditLog::record(
            action: AuditAction::CampaignRecipientsReplayed->value,
            summary: "{$by->fullName()} a rejoué {$count} remise(s) en échec de la campagne « {$campaign->title} ».",
            subject: $campaign,
            by: $by,
            context: ['recipients' => $count],
        );

        $this->reopen($campaign->recipients()->failed());

        $this->fanOut($campaign);

        return $count;
    }

    /**
     * Remet des lignes en attente et relâche leur réservation, faute de quoi
     * la remise suivante ne pourrait pas les réclamer.
     *
     * @param  Builder<CampaignRecipient>  $query
     */
    private function reopen($query): void
    {
        $query->update([
            'status' => CampaignRecipientStatus::Pending,
            'claimed_at' => null,
            'error' => null,
            'updated_at' => now(),
        ]);
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
     * ce qui traîne depuis plus d'un jour, et elle épargne désormais le fichier
     * d'une campagne (cf. `routes/console.php`).
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
}
