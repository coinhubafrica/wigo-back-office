<?php

namespace App\Notifications\Channels;

use App\Settings\WhatsappSettings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Netflie\WhatsAppCloudApi\Message\Template\Component;
use Netflie\WhatsAppCloudApi\Response\ResponseException;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;

/**
 * Canal de notification WhatsApp : envoie un modèle approuvé par Meta via
 * l'API WhatsApp Cloud.
 *
 * Une notification qui l'emprunte décrit son message dans `toWhatsapp()` et
 * le destinataire se lit sur `routeNotificationForWhatsapp()`. Hors d'une
 * fenêtre de conversation ouverte, Meta n'accepte que des modèles — d'où un
 * canal limité à `sendTemplate()`.
 *
 * Les identifiants sont lus en base à chaque envoi (`WhatsappSettings`) : un
 * jeton remplacé dans « Paramètres » sert dès le message suivant, sans
 * redémarrer les workers.
 */
class WhatsappChannel
{
    /**
     * @throws ResponseException quand Meta refuse l'envoi, pour que la file
     *                           retente
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (blank($to)) {
            return;
        }

        // Résolu à l'envoi et non au constructeur : le gestionnaire de canaux
        // garde son instance pour toute la vie d'un worker.
        $settings = app(WhatsappSettings::class);

        if (! $settings->isConfigured()) {
            Log::warning('WhatsApp : aucun identifiant configuré, message non envoyé', [
                'notification' => $notification::class,
            ]);

            return;
        }

        /** @var array{name: string, language: string, components: Component} $template */
        $template = $notification->toWhatsapp($notifiable);

        /*
        | `makeWith` plutôt que `new` : en production le conteneur construit le
        | client tel quel ; un test peut y substituer un client branché sur un
        | faux transport HTTP.
        */
        $client = app()->makeWith(WhatsAppCloudApi::class, ['config' => [
            'from_phone_number_id' => $settings->phone_number_id,
            'access_token' => $settings->access_token,
        ]]);

        try {
            $client->sendTemplate($to, $template['name'], $template['language'], $template['components']);
        } catch (ResponseException $exception) {
            // Seule l'erreur de Meta est journalisée : le corps du message
            // (un code OTP, par exemple) n'y figure jamais.
            $error = $exception->responseData()['error'] ?? [];

            Log::warning('WhatsApp : envoi refusé par Meta', [
                'notification' => $notification::class,
                'status' => $exception->httpStatusCode(),
                'code' => $error['code'] ?? null,
                'message' => $error['message'] ?? null,
            ]);

            throw $exception;
        }
    }
}
