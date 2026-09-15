<?php

namespace App\Notifications\Concerns;

use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;

/**
 * Compose le message FCM à partir de la charge utile déjà écrite en base.
 *
 * `toArray()` fait foi : la notification se décrit une fois, et le push reprend
 * exactement ce que l'écran « Notifications » affichera. Une notification qui
 * pousse autre chose que ce qu'elle enregistre est un bug en puissance.
 *
 * Messages « data-only » : aucun bloc `notification` n'est composé ici, c'est
 * Flutter qui décide de l'affichage. `FcmMessage::toArray()` filtre les clés
 * nulles, donc ne jamais appeler `notification()` suffit à garantir l'absence
 * du bloc — la propriété tient par construction, pas par convention.
 */
trait BuildsFcmMessage
{
    /**
     * Les canaux d'une notification poussée : la base toujours, le push quand
     * Firebase est configuré.
     *
     * Sans identifiants, `kreait` lève à la *résolution* du service, pas à
     * l'envoi : un environnement sans fichier de compte de service ferait
     * échouer l'appelant — un webhook Wave, un job de recharge — alors que le
     * push n'est qu'un réveil. On écarte le canal en amont ; la ligne en base
     * part dans tous les cas.
     *
     * @return list<string>
     */
    protected function pushedChannels(): array
    {
        return blank(config('firebase.projects.app.credentials'))
            ? ['database']
            : ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->data($this->stringify($this->toArray($notifiable)))
            ->custom([
                // Priorité haute : le push réveille l'application, il ne peut
                // pas attendre la prochaine fenêtre de veille de l'appareil.
                'android' => ['priority' => 'high'],
                'apns' => ['headers' => ['apns-priority' => '10']],
            ]);
    }

    /**
     * FCM n'accepte que des chaînes dans un message data-only, et
     * `FcmMessage::data()` lève sur toute autre valeur : la conversion doit
     * précéder l'appel.
     *
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
