<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Reprend les valeurs d'environnement déjà en place (`FLEET_*`) comme
     * point de départ : une installation qui les avait renseignées ne perd
     * rien, une installation neuve démarre à vide et se configure à l'écran.
     */
    public function up(): void
    {
        $this->migrator->add('fleet.base_url', (string) config('services.fleet.base_url', 'https://fleet-api.yango.tech'));
        $this->migrator->add('fleet.park_id', (string) config('services.fleet.park_id', ''));
        $this->migrator->addEncrypted('fleet.api_key', (string) config('services.fleet.api_key', ''));
    }
};
