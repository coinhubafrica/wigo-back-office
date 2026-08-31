<?php

use App\Models\IdempotencyKey;
use App\Models\MessageAttachment;
use App\Models\OtpCode;
use App\Settings\OtpSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planification
|--------------------------------------------------------------------------
*/

// Purge de l'historique OTP au-delà de la durée de rétention.
Schedule::call(fn () => OtpCode::query()
    ->where('created_at', '<', now()->subDays(app(OtpSettings::class)->retention_days))
    ->delete())
    ->daily()
    ->name('otp:prune-codes');

// Purge des clés d'idempotence périmées : une clé ne vaut que 24 h, la ligne
// ne sert plus à rien passé ce délai.
Schedule::call(fn () => IdempotencyKey::query()
    ->where('expires_at', '<', now())
    ->delete())
    ->daily()
    ->name('idempotency:prune-keys');

// Purge des pièces jointes jamais rattachées à un message : le mobile
// téléverse d'abord et rattache ensuite, un envoi abandonné laisse donc un
// fichier orphelin. Le fichier part avec la ligne — une purge qui ne
// nettoierait que la base laisserait le disque grossir sans fin.
Schedule::call(function (): void {
    MessageAttachment::query()
        ->whereNull('message_id')
        ->where('created_at', '<', now()->subDay())
        ->each(function (MessageAttachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        });
})
    ->daily()
    ->name('support:prune-orphan-attachments');
