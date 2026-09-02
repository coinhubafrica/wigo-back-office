<?php

namespace App\Services\Support;

use App\Enums\MessageType;
use App\Enums\SystemMessageEvent;
use App\Events\Support\MessageRead;
use App\Events\Support\MessageSent;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\SupportMessageReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Écriture des messages, et seul endroit qui tienne les compteurs.
 *
 * Deux invariants portés ici plutôt que dans les appelants :
 *
 * 1. Envoyer vaut lecture. L'expéditeur ne peut donc jamais accumuler du non-lu
 *    sur son propre message, et `markRead...()` n'a plus qu'à remettre à zéro.
 * 2. Un message entrant se rattache au ticket vivant s'il y en a un ; sinon il
 *    reste à trier. Le tri ne se déclenche donc que sur un sujet nouveau, pas
 *    sur chaque réponse — c'est ce qui garde la file « À trier » courte.
 *
 * Les compteurs sont mis à jour par `increment()` / `update()`, jamais lus puis
 * réécrits en PHP : un agent et un conducteur qui écrivent en même temps ne
 * doivent pas s'écraser mutuellement.
 */
class MessageService
{
    public function __construct(private ConversationResolver $conversations) {}

    /**
     * Message d'un conducteur. Ouvre sa conversation au besoin.
     *
     * @param  list<MessageAttachment>  $attachments
     */
    public function sendFromDriver(
        Driver $driver,
        ?string $body,
        array $attachments = [],
    ): Message {
        $conversation = $this->conversations->for($driver);

        $message = DB::transaction(function () use ($conversation, $driver, $body, $attachments): Message {
            $request = $conversation->liveSupportRequest()->first();

            $message = $this->write($conversation, [
                'support_request_id' => $request?->getKey(),
                'sender_type' => $driver->getMorphClass(),
                'sender_id' => $driver->getKey(),
                'sender_name' => $driver->fullName(),
                'type' => $attachments === [] ? MessageType::Text : MessageType::Attachment,
                'body' => $body,
            ], $attachments);

            // Le conducteur vient de lire son fil en y écrivant.
            $conversation->forceFill([
                'driver_unread_count' => 0,
                'driver_read_at' => now(),
            ]);
            $this->stampLastMessage($conversation, $message);

            $request?->newQuery()
                ->whereKey($request->getKey())
                ->increment('staff_unread_count');

            return $message;
        });

        // Après le commit : le worker relit le message par sa clé, il doit
        // être visible.
        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Réponse d'un agent, toujours rattachée à un ticket.
     *
     * @param  list<MessageAttachment>  $attachments
     */
    public function sendFromStaff(
        SupportRequest $request,
        User $agent,
        ?string $body,
        array $attachments = [],
        ?string $templateId = null,
    ): Message {
        $conversation = $request->conversation;

        $message = DB::transaction(function () use ($request, $agent, $body, $attachments, $templateId, $conversation): Message {

            $message = $this->write($conversation, [
                'support_request_id' => $request->getKey(),
                'sender_type' => $agent->getMorphClass(),
                'sender_id' => $agent->getKey(),
                'sender_name' => $agent->fullName(),
                'type' => $attachments === [] ? MessageType::Text : MessageType::Attachment,
                'body' => $body,
                'template_id' => $templateId,
            ], $attachments);

            $conversation->increment('driver_unread_count');
            $this->stampLastMessage($conversation, $message);

            // La première réponse arrête le premier chronomètre SLA, et une
            // seule fois : c'est le délai de première réponse, pas le dernier.
            $request->forceFill([
                'staff_unread_count' => 0,
                'staff_read_at' => now(),
                'first_response_at' => $request->first_response_at ?? now(),
            ])->save();

            return $message;
        });

        // Après la transaction : la notification est mise en file, et le
        // worker ne doit pas lire une ligne pas encore visible.
        $conversation->driver->notify(new SupportMessageReceived($message));
        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Réponse d'un agent hors ticket : le tri se règle d'une phrase, sans
     * ouvrir de dossier. Aucun chronomètre, aucune statistique de ticket —
     * `sendFromStaff()` ne convient pas, elle arrête le SLA d'un ticket.
     *
     * Répondre ne trie pas. La conversation reste dans la file, avec la réponse
     * visible dans le fil : l'agent voit ce qu'il a écrit, et attend le retour
     * du conducteur avant de décider. Il l'écarte ensuite (`dismissUntriaged`)
     * ou en fait un ticket. Trier à l'envoi faisait disparaître de l'écran ce
     * qu'on venait d'écrire — le défaut qui a motivé ce chemin.
     */
    public function sendUntriagedReply(Conversation $conversation, User $agent, ?string $body): Message
    {
        $message = DB::transaction(function () use ($conversation, $agent, $body): Message {
            $message = $this->write($conversation, [
                // Sans ticket : le message reste rattaché à la seule
                // conversation, et ne compte dans aucun délai.
                'support_request_id' => null,
                // Mais daté comme trié, et par son auteur : « à trier » décrit
                // ce que le conducteur attend, jamais ce que l'agent a déjà
                // écrit. Sans cela la réponse se compterait elle-même dans la
                // bannière, et un ticket ouvert plus tard l'avalerait.
                'triaged_at' => now(),
                'triaged_by_user_id' => $agent->getKey(),
                'sender_type' => $agent->getMorphClass(),
                'sender_id' => $agent->getKey(),
                'sender_name' => $agent->fullName(),
                'type' => MessageType::Text,
                'body' => $body,
            ]);

            $conversation->increment('driver_unread_count');
            $this->stampLastMessage($conversation, $message);

            return $message;
        });

        // Après la transaction : la notification est mise en file, et le
        // worker ne doit pas lire une ligne pas encore visible.
        $conversation->driver->notify(new SupportMessageReceived($message));
        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Message système : aucun émetteur, d'où l'absence de relation `sender`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function writeSystemMessage(
        Conversation $conversation,
        SystemMessageEvent $event,
        array $payload = [],
        ?SupportRequest $request = null,
        ?Campaign $campaign = null,
    ): Message {
        $message = DB::transaction(function () use ($conversation, $event, $payload, $request, $campaign): Message {
            $message = $this->write($conversation, [
                'support_request_id' => $request?->getKey(),
                'campaign_id' => $campaign?->getKey(),
                'type' => MessageType::System,
                'system_event' => $event,
                'system_payload' => $payload === [] ? null : $payload,
                // Rendu côté serveur en plus de l'évènement : une version
                // ancienne de l'application affiche cette phrase plutôt que
                // rien face à un évènement qu'elle ne connaît pas.
                'body' => $event->render($payload),
            ]);

            $conversation->increment('driver_unread_count');
            $this->stampLastMessage($conversation, $message);

            return $message;
        });

        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Le conducteur a ouvert son fil : tout ce qui ne vient pas de lui est lu.
     */
    public function markReadForDriver(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $conversation->messages()
                ->whereNull('read_at')
                ->where(fn ($query) => $query
                    ->whereNull('sender_type')
                    ->orWhere('sender_type', '!=', (new Driver)->getMorphClass()))
                ->update(['read_at' => now()]);

            $conversation->forceFill([
                'driver_unread_count' => 0,
                'driver_read_at' => now(),
            ])->save();
        });

        MessageRead::dispatch($conversation, 'driver');
    }

    /**
     * Un agent a ouvert le ticket : les messages du conducteur sont lus.
     */
    public function markReadForStaff(SupportRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $request->messages()
                ->whereNull('read_at')
                ->where('sender_type', (new Driver)->getMorphClass())
                ->update(['read_at' => now()]);

            $request->forceFill([
                'staff_unread_count' => 0,
                'staff_read_at' => now(),
            ])->save();
        });

        MessageRead::dispatch($request->conversation, 'user');
    }

    /**
     * Insère le message et rattache ses pièces jointes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<MessageAttachment>  $attachments
     */
    private function write(Conversation $conversation, array $attributes, array $attachments = []): Message
    {
        /** @var Message $message */
        $message = $conversation->messages()->create($attributes);

        foreach ($attachments as $attachment) {
            $attachment->forceFill(['message_id' => $message->getKey()])->save();
        }

        return $message;
    }

    /**
     * Recopie l'aperçu sur la conversation : la liste et le badge ne doivent
     * jamais avoir à ouvrir `messages`.
     */
    private function stampLastMessage(Conversation $conversation, Message $message): void
    {
        $preview = $message->body !== null && $message->body !== ''
            ? Str::limit($message->body, 157)
            : 'Pièce jointe';

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => $preview,
            'last_message_sender_type' => $message->sender_type,
        ])->save();
    }
}
