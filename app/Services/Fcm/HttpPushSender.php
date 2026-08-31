<?php

namespace App\Services\Fcm;

use App\Contracts\PushSender;
use App\Models\Driver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi réel via FCM HTTP v1.
 *
 * Un jeton refusé (`UNREGISTERED`, `INVALID_ARGUMENT`) est effacé : le
 * conducteur a désinstallé ou réinstallé l'application, et le garder ferait
 * échouer chaque envoi suivant.
 *
 * Un seul jeton par conducteur : une réinstallation ou un second appareil perd
 * le précédent. Le passage à une table de jetons est un chantier assumé pour
 * plus tard.
 */
class HttpPushSender implements PushSender
{
    /**
     * @param  array<string, string>  $data
     */
    public function send(Driver $driver, array $data): bool
    {
        $token = $driver->fcm_token;

        if ($token === null) {
            return false;
        }

        $projectId = (string) config('services.fcm.project_id');

        $response = Http::withToken($this->accessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    // Data-only : pas de bloc `notification`, l'application
                    // garde la main sur l'affichage et le réveil en arrière-plan.
                    'data' => $data,
                    'android' => ['priority' => 'high'],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        if ($this->tokenIsDead($response->json('error.status'))) {
            $driver->forceFill(['fcm_token' => null])->save();

            return false;
        }

        Log::warning('Push FCM en échec', [
            'driver' => $driver->getKey(),
            'status' => $response->status(),
        ]);

        return false;
    }

    private function tokenIsDead(mixed $status): bool
    {
        return in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], strict: true);
    }

    /**
     * Jeton d'accès du compte de service. Laissé à la configuration : la
     * fabrique OAuth de Google n'a pas sa place ici.
     */
    private function accessToken(): string
    {
        return (string) config('services.fcm.access_token');
    }
}
