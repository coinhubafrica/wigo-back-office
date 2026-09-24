<?php

namespace App\Settings;

use Spatie\LaravelSettings\Attributes\ShouldBeEncrypted;
use Spatie\LaravelSettings\Settings;

/**
 * Accès à l'API WhatsApp Cloud de Meta, par laquelle partent les codes OTP.
 *
 * En base et non dans l'environnement, comme Yango et Wave : un jeton Meta se
 * renouvelle, et le remplacer ne doit pas attendre une mise en production.
 *
 * `access_token` est chiffré au repos par `APP_KEY` : une lecture de la table
 * `settings` ne doit pas suffire à écrire aux conducteurs au nom de WiGO.
 *
 * Vide = non configuré. `WhatsappChannel` n'appelle alors pas Meta et le
 * journalise, plutôt que de faire passer une configuration absente pour un
 * refus de l'API.
 */
class WhatsappSettings extends Settings
{
    /** Identifiant du numéro expéditeur (« Phone number ID » chez Meta). */
    public string $phone_number_id;

    #[ShouldBeEncrypted]
    public string $access_token;

    public static function group(): string
    {
        return 'whatsapp';
    }

    /**
     * Vrai quand les deux valeurs nécessaires à un envoi sont présentes.
     */
    public function isConfigured(): bool
    {
        return filled($this->phone_number_id) && filled($this->access_token);
    }
}
