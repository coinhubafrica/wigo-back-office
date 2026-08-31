<?php

namespace App\Services\Fcm;

use App\Contracts\PushSender;
use App\Models\Driver;
use Illuminate\Support\Facades\Log;

/**
 * Implémentation locale : journalise le push au lieu de l'émettre. Utilisée en
 * développement et en test, où aucun appel réseau ne doit partir.
 */
class LogPushSender implements PushSender
{
    /** @var list<array{driver_id: string, data: array<string, string>}> */
    private array $sent = [];

    /**
     * @param  array<string, string>  $data
     */
    public function send(Driver $driver, array $data): bool
    {
        if ($driver->fcm_token === null) {
            return false;
        }

        $this->sent[] = ['driver_id' => (string) $driver->getKey(), 'data' => $data];

        Log::info('Push (local)', ['driver' => $driver->getKey(), 'data' => $data]);

        return true;
    }

    /**
     * @return list<array{driver_id: string, data: array<string, string>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
