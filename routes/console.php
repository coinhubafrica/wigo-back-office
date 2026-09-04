<?php

use App\Models\Campaign;
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
            /*
            | Le fichier d'une campagne est partagé par tous ses messages : une
            | seule ligne orpheline — un envoi interrompu entre la création de
            | la pièce jointe et son rattachement — emporterait sinon l'image
            | de l'envoi entier, et l'écran de détail tomberait en 404. On jette
            | la ligne, on garde le fichier tant qu'une campagne le revendique.
            */
            $claimed = Campaign::query()
                ->where('image_path', $attachment->path)
                ->where('image_disk', $attachment->disk)
                ->exists();

            if (! $claimed) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            $attachment->delete();
        });
})
    ->daily()
    ->name('support:prune-orphan-attachments');

// Rapprochement du parc Yango : conducteurs, véhicules et affectations. Toutes
// les heures — le parc bouge à la journée, et une passe manquée se rattrape
// d'elle-même à la suivante. `withoutOverlapping` parce qu'une passe longue ne
// doit pas croiser la suivante (cf. SyncFleetJob).
Schedule::command('fleet:sync')
    ->hourly()
    ->withoutOverlapping()
    ->name('fleet:sync');
