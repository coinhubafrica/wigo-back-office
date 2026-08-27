<?php

namespace App\Contracts;

use App\Enums\OtpChannel;

/**
 * Envoi des codes OTP par SMS ou WhatsApp.
 */
interface SmsSender
{
    public function send(string $phone, string $message, OtpChannel $channel): bool;
}
