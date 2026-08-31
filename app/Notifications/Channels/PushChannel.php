<?php

namespace App\Notifications\Channels;

use App\Contracts\PushSender;
use App\Models\Driver;
use Illuminate\Notifications\Notification;

/**
 * Canal « push » des notifications.
 *
 * La notification déclare `toPush()` ; à défaut, la charge utile de la ligne
 * en base est reprise. Les valeurs sont converties en chaînes : FCM n'accepte
 * rien d'autre dans un message data-only.
 */
class PushChannel
{
    public function __construct(private PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Driver) {
            return;
        }

        // `toPush()` prime, `toArray()` sert de repli : la charge utile du
        // push est alors exactement celle écrite en base, ce qui évite de
        // décrire deux fois la même notification. Une notification qui ne
        // déclare ni l'une ni l'autre n'a rien à pousser.
        $method = match (true) {
            method_exists($notification, 'toPush') => 'toPush',
            method_exists($notification, 'toArray') => 'toArray',
            default => null,
        };

        if ($method === null) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->{$method}($notifiable);

        $this->sender->send($notifiable, $this->stringify($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function stringify(array $payload): array
    {
        $data = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            $data[$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $data;
    }
}
