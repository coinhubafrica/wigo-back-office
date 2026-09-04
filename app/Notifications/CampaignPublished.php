<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Une campagne vient de partir.
 *
 * Écrite en base d'abord, comme les autres : l'écran « Notifications » lit
 * cette table, le push n'est qu'un réveil.
 */
class CampaignPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Campaign $campaign) {}

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
            'type' => 'campaign',
            'category' => 'campaign',
            'title' => $this->campaign->title,
            'body' => $this->campaign->body,
            'campaign_id' => $this->campaign->getKey(),
            // Un booléen, pas une URL : cette charge utile est écrite en base
            // et relue longtemps après, alors qu'une URL signée expire en une
            // heure. L'application ouvre le fil, où le message porte sa pièce
            // jointe avec une URL fraîche.
            'has_image' => $this->campaign->hasImage(),
            'deeplink' => $this->campaign->deeplink ?? 'wigo://campaigns/'.$this->campaign->getKey(),
        ];
    }
}
