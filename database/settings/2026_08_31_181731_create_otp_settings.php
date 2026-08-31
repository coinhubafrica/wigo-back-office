<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Valeurs initiales reprises telles quelles de `config/wigo.php`, pour que
     * le comportement soit identique avant et après la bascule.
     */
    public function up(): void
    {
        $this->migrator->add('otp.length', 6);
        $this->migrator->add('otp.ttl_minutes', 5);
        $this->migrator->add('otp.max_attempts', 5);
        $this->migrator->add('otp.lock_minutes', 15);
        $this->migrator->add('otp.default_channel', 'sms');
        $this->migrator->add('otp.throttle_max_sends', 3);
        $this->migrator->add('otp.throttle_decay_minutes', 10);
        $this->migrator->add('otp.retention_days', 30);
    }
};
