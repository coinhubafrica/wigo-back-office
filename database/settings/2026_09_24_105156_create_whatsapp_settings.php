<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Accès à l'API WhatsApp Cloud de Meta, qui porte désormais les codes OTP.
     *
     * Les deux valeurs démarrent à vide et se saisissent dans « Paramètres ».
     * Le jeton est chiffré au repos ; l'identifiant du numéro expéditeur ne
     * l'est pas — il ne permet rien sans le jeton.
     */
    public function up(): void
    {
        $this->migrator->add('whatsapp.phone_number_id', '');
        $this->migrator->addEncrypted('whatsapp.access_token', '');
    }
};
