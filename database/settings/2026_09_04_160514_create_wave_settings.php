<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Un groupe de réglages par compte Wave, et non un groupe unique à quatre
     * champs : la boutique et la recharge Yango sont deux comptes sans rapport,
     * qui s'ouvrent, se règlent et se renouvellent séparément. Les tenir dans
     * le même groupe invitait à les enregistrer d'un seul geste.
     *
     * Les deux démarrent à vide et se configurent dans « Paramètres ». Les
     * variables `WAVE_BASE_URL` / `WAVE_API_KEY` / `WAVE_WEBHOOK_SECRET` ont
     * été retirées : elles ne portaient qu'un seul jeu de clés.
     */
    public function up(): void
    {
        $this->migrator->addEncrypted('wave_shop.api_key', '');
        $this->migrator->addEncrypted('wave_shop.webhook_secret', '');
        $this->migrator->addEncrypted('wave_topup.api_key', '');
        $this->migrator->addEncrypted('wave_topup.webhook_secret', '');
    }
};
