<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Barème SLA initial. Les délais sont en minutes et se comptent en temps
     * réel : le support est ouvert en continu, aucun calendrier ouvré n'entre
     * dans le calcul.
     *
     * La priorité découle de la catégorie — un problème d'argent ou d'accès au
     * compte passe devant une question de boutique.
     */
    public function up(): void
    {
        $this->migrator->add('support.sla', [
            'payment' => ['priority' => 'high', 'first_response_minutes' => 60, 'resolution_minutes' => 480],
            'account' => ['priority' => 'high', 'first_response_minutes' => 60, 'resolution_minutes' => 480],
            'shop' => ['priority' => 'normal', 'first_response_minutes' => 240, 'resolution_minutes' => 2880],
            'vehicle' => ['priority' => 'normal', 'first_response_minutes' => 240, 'resolution_minutes' => 2880],
            'cnps' => ['priority' => 'normal', 'first_response_minutes' => 240, 'resolution_minutes' => 2880],
            'other' => ['priority' => 'low', 'first_response_minutes' => 1440, 'resolution_minutes' => 7200],
        ]);

        $this->migrator->add('support.attachment_max_kilobytes', 10240);
        $this->migrator->add('support.suspended_drivers_may_write', true);
    }
};
