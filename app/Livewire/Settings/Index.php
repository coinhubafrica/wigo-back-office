<?php

namespace App\Livewire\Settings;

use App\Enums\BackOfficeModule;
use App\Services\Fleet\FleetConnectionTester;
use App\Settings\FleetSettings;
use App\Settings\OtpSettings;
use App\Settings\RechargeSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Réglages métier : barème OTP, plafonds de recharge et accès au parc Yango.
 *
 * Seules les valeurs que le métier ajuste sont ici. Les interrupteurs de
 * sécurité et de déploiement (contournement d'OTP, jeton de documentation,
 * version des CGU) restent dans `config/wigo.php`, pilotés par
 * l'environnement — ils ne doivent pas se changer depuis une page web.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Settings])]
class Index extends Component
{
    public int $otpLength = 6;

    public int $otpTtlMinutes = 5;

    public int $otpMaxAttempts = 5;

    public int $otpLockMinutes = 15;

    public int $otpThrottleMaxSends = 3;

    public int $otpThrottleDecayMinutes = 10;

    public int $otpRetentionDays = 30;

    public int $rechargeMinAmount = 500;

    public int $rechargeMaxAmount = 100000;

    public int $rechargeDailyCap = 150000;

    public int $rechargeBalanceTtlMinutes = 10;

    public string $fleetBaseUrl = '';

    public string $fleetParkId = '';

    /**
     * Jamais pré-rempli avec la clé enregistrée : une clé en clair dans le HTML
     * de la page serait lisible par toute extension du navigateur. Vide à
     * l'affichage signifie « on garde celle déjà enregistrée ».
     */
    public string $fleetApiKey = '';

    /** Verdict du dernier test de connexion, le temps de la requête. */
    public ?bool $fleetTestSucceeded = null;

    public ?string $fleetTestMessage = null;

    public function mount(OtpSettings $otp, RechargeSettings $recharge, FleetSettings $fleet): void
    {
        $this->otpLength = $otp->length;
        $this->otpTtlMinutes = $otp->ttl_minutes;
        $this->otpMaxAttempts = $otp->max_attempts;
        $this->otpLockMinutes = $otp->lock_minutes;
        $this->otpThrottleMaxSends = $otp->throttle_max_sends;
        $this->otpThrottleDecayMinutes = $otp->throttle_decay_minutes;
        $this->otpRetentionDays = $otp->retention_days;

        $this->rechargeMinAmount = $recharge->min_amount;
        $this->rechargeMaxAmount = $recharge->max_amount;
        $this->rechargeDailyCap = $recharge->daily_cap;
        $this->rechargeBalanceTtlMinutes = $recharge->balance_ttl_minutes;

        $this->fleetBaseUrl = $fleet->base_url;
        $this->fleetParkId = $fleet->park_id;
    }

    public function saveOtp(OtpSettings $otp): void
    {
        $this->validate([
            // La longueur borne un `random_int(0, 10 ** $length - 1)` : au-delà
            // de neuf chiffres le tirage déborde sur les plateformes 32 bits.
            'otpLength' => 'required|integer|min:4|max:9',
            'otpTtlMinutes' => 'required|integer|min:1|max:60',
            'otpMaxAttempts' => 'required|integer|min:1|max:20',
            'otpLockMinutes' => 'required|integer|min:1|max:1440',
            'otpThrottleMaxSends' => 'required|integer|min:1|max:20',
            'otpThrottleDecayMinutes' => 'required|integer|min:1|max:1440',
            'otpRetentionDays' => 'required|integer|min:1|max:365',
        ]);

        $otp->length = $this->otpLength;
        $otp->ttl_minutes = $this->otpTtlMinutes;
        $otp->max_attempts = $this->otpMaxAttempts;
        $otp->lock_minutes = $this->otpLockMinutes;
        $otp->throttle_max_sends = $this->otpThrottleMaxSends;
        $otp->throttle_decay_minutes = $this->otpThrottleDecayMinutes;
        $otp->retention_days = $this->otpRetentionDays;
        $otp->save();

        $this->dispatch('toast', message: __('backoffice.settings.otp_saved'));
    }

    public function saveRecharge(RechargeSettings $recharge): void
    {
        $this->validate([
            'rechargeMinAmount' => 'required|integer|min:100',
            'rechargeMaxAmount' => 'required|integer|gt:rechargeMinAmount',
            // Le plafond journalier borne le cumul : en dessous d'une recharge
            // unitaire maximale, la seconde recharge du jour serait toujours
            // refusée.
            'rechargeDailyCap' => 'required|integer|gte:rechargeMaxAmount',
            'rechargeBalanceTtlMinutes' => 'required|integer|min:1|max:1440',
        ]);

        $recharge->min_amount = $this->rechargeMinAmount;
        $recharge->max_amount = $this->rechargeMaxAmount;
        $recharge->daily_cap = $this->rechargeDailyCap;
        $recharge->balance_ttl_minutes = $this->rechargeBalanceTtlMinutes;
        $recharge->save();

        $this->dispatch('toast', message: __('backoffice.settings.recharge_saved'));
    }

    public function saveFleet(FleetSettings $fleet): void
    {
        $this->validate([
            'fleetBaseUrl' => 'required|url',
            'fleetParkId' => 'required|string|max:255',
            // Facultative : laissée vide, la clé déjà enregistrée est conservée.
            'fleetApiKey' => 'nullable|string|max:255',
        ]);

        $fleet->base_url = $this->fleetBaseUrl;
        $fleet->park_id = $this->fleetParkId;

        if (filled($this->fleetApiKey)) {
            $fleet->api_key = $this->fleetApiKey;
        }

        $fleet->save();

        // La clé ne repart pas vers le navigateur une fois enregistrée.
        $this->fleetApiKey = '';
        $this->fleetTestSucceeded = null;
        $this->fleetTestMessage = null;

        $this->dispatch('toast', message: __('backoffice.settings.fleet_saved'));
    }

    /**
     * Teste les identifiants enregistrés en lecture seule : un conducteur
     * demandé, rien d'écrit. Enregistrer d'abord, tester ensuite.
     */
    public function testFleet(FleetConnectionTester $tester): void
    {
        $result = $tester->test();

        $this->fleetTestSucceeded = $result->succeeded;

        $this->fleetTestMessage = match (true) {
            $result->succeeded && $result->empty => (string) __('backoffice.settings.fleet_test_empty'),
            $result->succeeded => (string) __('backoffice.settings.fleet_test_ok'),
            $result->status !== null => (string) __('backoffice.settings.fleet_test_failed_status', [
                'status' => $result->status,
                'message' => $result->message ?? '',
            ]),
            default => $result->message,
        };
    }

    public function render(FleetSettings $fleet): View
    {
        /** @var view-string $view */
        $view = 'livewire.settings.index';

        return view($view, [
            // La clé elle-même ne quitte jamais le serveur : seule sa présence
            // est publiée, pour dire si la synchronisation peut tourner et si
            // le test a un sens.
            'fleetKeyStored' => filled($fleet->api_key),
        ]);
    }
}
