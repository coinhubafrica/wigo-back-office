<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsappChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Netflie\WhatsAppCloudApi\Message\Template\Component;

/**
 * Code de connexion envoyé au conducteur par WhatsApp, via le modèle Meta
 * `wigo_otp` (catégorie « authentification », bouton « Copier le code »).
 *
 * Mise en file et chiffrée (`ShouldBeEncrypted`) : le code en clair ne doit
 * pas se lire dans la charge utile du job, pas plus qu'il n'est écrit en base.
 *
 * WhatsApp seulement : pas de ligne `database` — un code n'a rien à faire
 * dans l'historique des notifications du conducteur — ni de push.
 */
class WhatsappOtpCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Nom du modèle approuvé chez Meta. */
    public const string TEMPLATE = 'wigo_otp';

    /** Langue sous laquelle le modèle a été approuvé. */
    public const string LANGUAGE = 'fr';

    /**
     * Un code vit quelques minutes : au-delà de quelques essais rapprochés,
     * un renvoi n'apporterait qu'un code déjà expiré.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(public readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WhatsappChannel::class];
    }

    /**
     * Un modèle d'authentification attend le code deux fois : dans le corps
     * (`{{1}}`) et en paramètre du bouton « Copier le code » (bouton URL,
     * index 0) — Meta refuse l'envoi s'il en manque un.
     *
     * @return array{name: string, language: string, components: Component}
     */
    public function toWhatsapp(object $notifiable): array
    {
        return [
            'name' => self::TEMPLATE,
            'language' => self::LANGUAGE,
            'components' => new Component(
                body: [
                    ['type' => 'text', 'text' => $this->code],
                ],
                buttons: [
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => 0,
                        'parameters' => [['type' => 'text', 'text' => $this->code]],
                    ],
                ],
            ),
        ];
    }
}
