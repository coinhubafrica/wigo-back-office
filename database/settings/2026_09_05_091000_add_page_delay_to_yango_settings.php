<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * 250 ms : assez pour que Yango ne voie plus une rafale, assez peu pour
     * qu'une passe de cinquante pages ne coûte que quelques secondes de plus.
     * Point de départ, pas vérité — le réglage existe pour être corrigé.
     */
    public function up(): void
    {
        $this->migrator->add('yango.page_delay_ms', 250);
    }

    public function down(): void
    {
        $this->migrator->delete('yango.page_delay_ms');
    }
};
