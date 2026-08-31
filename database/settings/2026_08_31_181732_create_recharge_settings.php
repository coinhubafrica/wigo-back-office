<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Valeurs initiales reprises telles quelles de `config/wigo.php`.
     *
     * Le prototype mobile annonce 150 000 par jour, l'`openapi.yaml` du
     * handoff 200 000 : c'est ce réglage qui tranche, et il se change
     * désormais depuis « Paramètres ».
     */
    public function up(): void
    {
        $this->migrator->add('recharge.min_amount', 500);
        $this->migrator->add('recharge.max_amount', 100000);
        $this->migrator->add('recharge.daily_cap', 150000);
        $this->migrator->add('recharge.balance_ttl_minutes', 10);
    }
};
