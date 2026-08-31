<?php

namespace App\Notifications;

use App\Models\Message;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * « Le support vous a répondu. »
 *
 * Écrite en base d'abord, comme `RechargeCredited` : l'écran « Notifications »
 * lit cette table, le push n'est qu'un réveil pour une application en
 * arrière-plan.
 *
 * Mise en file : la latence d'un push est invisible, et l'agent n'a pas à
 * attendre l'aller-retour FCM pour que sa réponse parte.
 */
class SupportMessageReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Message $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_message',
            'category' => 'support',
            'title' => 'Le support vous a répondu',
            'body' => $this->preview(),
            'deeplink' => 'wigo://support',
        ];
    }

    private function preview(): string
    {
        $body = $this->message->body;

        return $body === null || $body === ''
            ? 'Pièce jointe'
            : Str::limit($body, 120);
    }
}
