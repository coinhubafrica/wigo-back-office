<?php

use App\Models\IdempotencyKey;
use App\Models\OtpCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
    ->where('created_at', '<', now()->subDays((int) config('wigo.otp.retention_days')))
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
