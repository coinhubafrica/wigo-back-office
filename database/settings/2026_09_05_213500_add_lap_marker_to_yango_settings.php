<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Début du tour de parc en cours.
     *
     * Un tour s'étale désormais sur plusieurs passes : le repère de fraîcheur
     * qui décide des lignes « non remontées » doit dater du tour, pas de la
     * passe. Mesuré depuis la passe, il accuserait Yango d'avoir oublié tous
     * les conducteurs rapprochés une heure plus tôt par la passe précédente.
     *
     * Chaîne vide = aucun tour commencé.
     */
    public function up(): void
    {
        $this->migrator->add('yango.lap_started_at', '');
    }

    public function down(): void
    {
        $this->migrator->delete('yango.lap_started_at');
    }
};
