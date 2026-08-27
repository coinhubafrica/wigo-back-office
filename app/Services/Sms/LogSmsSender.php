<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use Illuminate\Support\Facades\Log;

/**
 * Implémentation locale : journalise le message au lieu de l'envoyer. Utilisée
 * en développement et en test, où aucun appel réseau ne doit être émis.
 */
class LogSmsSender implements SmsSender
{
    /** @var list<array{phone: string, message: string, channel: string}> */
    private array $sent = [];

    public function send(string $phone, string $message, OtpChannel $channel): bool
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message, 'channel' => $channel->value];

        Log::info('OTP (local)', ['phone' => $phone, 'channel' => $channel->value, 'message' => $message]);

        return true;
    }

    /**
     * @return list<array{phone: string, message: string, channel: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
