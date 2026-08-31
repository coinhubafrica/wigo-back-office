<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Settings\OtpSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie des codes OTP. Chaque envoi crée une ligne dans `otp_codes` :
 * l'historique est conservé et plusieurs codes peuvent coexister le temps de
 * leur validité (un envoi n'invalide pas le précédent, ce qui évite de rejeter
 * un code encore en transit).
 *
 * Le verrouillage n'est pas un champ du conducteur : il se déduit du dernier
 * code ayant atteint le seuil d'échecs (`locked_until`).
 */
class OtpService
{
    /**
     * Dernier code émis en clair, non persisté (cf. `lastPlainCode()`).
     */
    private ?string $lastPlainCode = null;

    public function __construct(private SmsSender $smsSender, private OtpSettings $settings) {}

    /**
     * Émet un code et l'envoie sur le canal demandé.
     *
     * @throws ValidationException si le conducteur est verrouillé
     */
    public function send(Driver $driver, OtpChannel $channel, ?string $requestIp = null): OtpCode
    {
        $this->assertNotLocked($driver);

        $code = $this->generateCode();
        $ttl = $this->settings->ttl_minutes;

        $otpCode = $driver->otpCodes()->create([
            'code_hash' => Hash::make($code),
            'channel' => $channel,
            'sent_at' => now(),
            'expires_at' => now()->addMinutes($ttl),
            'request_ip' => $requestIp,
        ]);

        $this->smsSender->send(
            $driver->phone,
            __('otp.message', ['code' => $code, 'minutes' => $ttl]),
            $channel,
        );

        // Conservé en mémoire uniquement : jamais persisté en clair.
        $this->lastPlainCode = $code;

        return $otpCode;
    }

    /**
     * Code en clair du dernier envoi, si et seulement si l'exposition est
     * autorisée. Destiné aux tests automatisés et au développement local.
     */
    public function lastPlainCode(): ?string
    {
        return $this->exposesCode() ? $this->lastPlainCode : null;
    }

    /**
     * L'exposition du code est un contournement complet de l'authentification :
     * elle est refusée en production quelle que soit la configuration.
     */
    public function exposesCode(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return (bool) config('wigo.otp.expose_code');
    }

    /**
     * Vérifie le code soumis contre tous les codes encore utilisables du
     * conducteur. Le code correspondant est consommé ; sinon l'échec est
     * comptabilisé sur chacun d'eux et le seuil déclenche un verrouillage.
     *
     * @throws ValidationException
     */
    public function verify(Driver $driver, string $code): void
    {
        $this->assertNotLocked($driver);

        $usable = $driver->otpCodes()->usable()->lockForUpdate()->get();

        if ($usable->isEmpty()) {
            throw ValidationException::withMessages([
                'code' => [$this->hasRecentlyExpired($driver) ? __('otp.expired') : __('otp.not_requested')],
            ]);
        }

        $match = $usable->first(fn (OtpCode $candidate): bool => Hash::check($code, $candidate->code_hash));

        if ($match === null) {
            $this->registerFailedAttempt($driver, $usable);

            throw ValidationException::withMessages([
                'code' => [__('otp.invalid')],
            ]);
        }

        DB::transaction(function () use ($driver, $match, $usable): void {
            $match->forceFill(['consumed_at' => now()])->save();

            // Les autres codes en vol deviennent inutilisables : une connexion
            // réussie clôt la séquence d'authentification.
            $driver->otpCodes()
                ->whereIn('id', $usable->where('id', '!=', $match->id)->pluck('id'))
                ->update(['consumed_at' => now()]);

            $driver->forceFill(['last_login_at' => now()])->save();
        });
    }

    /**
     * Fin du verrouillage courant, ou `null` si le conducteur n'est pas bloqué.
     */
    public function lockedUntil(Driver $driver): ?Carbon
    {
        $lockedUntil = $driver->otpCodes()
            ->whereNotNull('locked_until')
            ->orderByDesc('locked_until')
            ->value('locked_until');

        if ($lockedUntil === null) {
            return null;
        }

        $lockedUntil = Carbon::parse($lockedUntil);

        return $lockedUntil->isFuture() ? $lockedUntil : null;
    }

    /**
     * @throws ValidationException
     */
    private function assertNotLocked(Driver $driver): void
    {
        $lockedUntil = $this->lockedUntil($driver);

        if ($lockedUntil === null) {
            return;
        }

        throw ValidationException::withMessages([
            'phone' => [__('otp.locked', [
                'minutes' => max(1, (int) ceil(now()->diffInMinutes($lockedUntil, absolute: true))),
            ])],
        ]);
    }

    /**
     * Incrémente le compteur d'échecs des codes en vol. Au seuil, ils sont
     * invalidés et le dernier porte la borne de verrouillage.
     *
     * @param  Collection<int, OtpCode>  $usable
     */
    private function registerFailedAttempt(Driver $driver, $usable): void
    {
        $maxAttempts = $this->settings->max_attempts;
        $attempts = $usable->max('attempts') + 1;

        if ($attempts < $maxAttempts) {
            $driver->otpCodes()->whereIn('id', $usable->pluck('id'))->increment('attempts');

            return;
        }

        $driver->otpCodes()->whereIn('id', $usable->pluck('id'))->update([
            'attempts' => $attempts,
            'consumed_at' => now(),
            'locked_until' => now()->addMinutes($this->settings->lock_minutes),
        ]);
    }

    /**
     * Distingue « code expiré » de « aucun code demandé » pour le message rendu.
     */
    private function hasRecentlyExpired(Driver $driver): bool
    {
        return $driver->otpCodes()
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', now())
            ->exists();
    }

    /**
     * Code numérique à zéros non significatifs, tiré d'une source cryptographique.
     */
    private function generateCode(): string
    {
        $length = $this->settings->length;

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }
}
