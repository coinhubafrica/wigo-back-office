<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi réel via le fournisseur configuré. Le canal WhatsApp et le canal SMS
 * peuvent pointer vers deux fournisseurs distincts.
 */
class HttpSmsSender implements SmsSender
{
    public function send(string $phone, string $message, OtpChannel $channel): bool
    {
        $endpoint = $channel === OtpChannel::Whatsapp
            ? ['url' => config('services.sms.whatsapp_base_url'), 'key' => config('services.sms.whatsapp_api_key')]
            : ['url' => config('services.sms.base_url'), 'key' => config('services.sms.api_key')];

        if (blank($endpoint['url'])) {
            Log::warning('OTP : aucun fournisseur configuré pour ce canal', ['channel' => $channel->value]);

            return false;
        }

        $response = Http::withToken((string) $endpoint['key'])
            ->timeout(15)
            ->post((string) $endpoint['url'], [
                'to' => $phone,
                'from' => config('services.sms.sender_id'),
                'message' => $message,
                'channel' => $channel->value,
            ]);

        if ($response->failed()) {
            Log::warning('OTP : envoi refusé par le fournisseur', [
                'channel' => $channel->value,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
