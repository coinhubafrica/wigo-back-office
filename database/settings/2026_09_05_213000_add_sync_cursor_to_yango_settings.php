<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Point de reprise de la passe parc.
     *
     * Yango coupe une passe bien avant la fin d'un grand parc — quota mesuré à
     * une vingtaine de pages, là où vingt-cinq mille conducteurs en demandent
     * plus du double. Reprendre à zéro à chaque tic du planificateur
     * repasserait indéfiniment sur les mêmes premières pages sans jamais
     * atteindre les dernières.
     *
     * Zéro = passe complète à faire depuis le début. La valeur vit en base et
     * non en cache : un cache vidé ne doit pas faire perdre la progression.
     */
    public function up(): void
    {
        $this->migrator->add('yango.drivers_offset', 0);
        $this->migrator->add('yango.vehicles_offset', 0);
    }

    public function down(): void
    {
        $this->migrator->delete('yango.drivers_offset');
        $this->migrator->delete('yango.vehicles_offset');
    }
};
