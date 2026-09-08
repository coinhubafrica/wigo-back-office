<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * La suspension locale disparaît : couper un conducteur se décide sur la
     * plateforme Yango. Le réglage qui ouvrait l'écriture support aux
     * conducteurs suspendus n'a donc plus d'objet — un conducteur radié garde
     * l'accès au support, c'est là qu'il conteste.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('support.suspended_drivers_may_write');
    }

    public function down(): void
    {
        $this->migrator->addIfNotExists('support.suspended_drivers_may_write', true);
    }
};
