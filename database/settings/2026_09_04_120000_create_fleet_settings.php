<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Démarre à vide : le parc se configure à l'écran, dans « Paramètres ».
     *
     * Les anciennes variables `FLEET_BASE_URL` / `FLEET_PARK_ID` /
     * `FLEET_API_KEY` ont été retirées — ces réglages font désormais foi pour
     * toute l'intégration Yango.
     */
    public function up(): void
    {
        $this->migrator->add('fleet.base_url', 'https://fleet-api.yango.tech');
        $this->migrator->add('fleet.park_id', '');
        $this->migrator->addEncrypted('fleet.api_key', '');
    }
};
