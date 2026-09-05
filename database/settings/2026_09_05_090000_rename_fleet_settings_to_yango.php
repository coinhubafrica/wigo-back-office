<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Le groupe suit le nom de l'intégration : elle ne parle qu'à Yango.
     *
     * `rename()` déplace la charge utile telle quelle, si bien que
     * `api_key` reste chiffrée par `APP_KEY` — la valeur n'est jamais
     * déchiffrée en chemin, seule sa clé change.
     */
    public function up(): void
    {
        $this->migrator->rename('fleet.base_url', 'yango.base_url');
        $this->migrator->rename('fleet.park_id', 'yango.park_id');
        $this->migrator->rename('fleet.api_key', 'yango.api_key');
    }

    public function down(): void
    {
        $this->migrator->rename('yango.base_url', 'fleet.base_url');
        $this->migrator->rename('yango.park_id', 'fleet.park_id');
        $this->migrator->rename('yango.api_key', 'fleet.api_key');
    }
};
