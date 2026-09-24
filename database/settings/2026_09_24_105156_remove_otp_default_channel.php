<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Les codes OTP ne partent plus que par WhatsApp : le canal par défaut n'a
     * plus rien à départager, et le fournisseur SMS a été retiré.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('otp.default_channel');
    }

    public function down(): void
    {
        $this->migrator->addIfNotExists('otp.default_channel', 'sms');
    }
};
