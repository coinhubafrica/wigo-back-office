<?php

namespace App\Notifications;

use App\Models\Broadcast;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Une diffusion vient de partir.
 *
 * Écrite en base d'abord, comme les autres : l'écran « Notifications » lit
 * cette table, le push n'est qu'un réveil.
 */
class BroadcastPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Broadcast $broadcast) {}

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
            'type' => 'broadcast',
            'category' => 'broadcast',
            'title' => $this->broadcast->title,
            'body' => $this->broadcast->body,
            'broadcast_id' => $this->broadcast->getKey(),
            'deeplink' => $this->broadcast->deeplink ?? 'wigo://broadcasts/'.$this->broadcast->getKey(),
        ];
    }
}
