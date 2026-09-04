<?php

namespace App\Livewire\Settings;

use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Fleet\FleetConnectionTester;
use App\Settings\FleetSettings;
use App\Settings\OtpSettings;
use App\Settings\RechargeSettings;
use App\Settings\WaveAccountSettings;
use App\Settings\WaveShopSettings;
use App\Settings\WaveTopupSettings;
use App\Support\SecretMask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Réglages métier : barème OTP, plafonds de recharge, accès au parc Yango et
 * clés des deux comptes Wave (boutique et recharge).
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
     *
     * Ce que le champ montre au repos est un *aperçu masqué* (`SecretMask`),
     * rendu en filigrane et non comme valeur : il nomme la clé en place sans
     * la publier, et disparaît dès la première frappe.
     */
    public string $fleetApiKey = '';

    /** Verdict du dernier test de connexion, le temps de la requête. */
    public ?bool $fleetTestSucceeded = null;

    public ?string $fleetTestMessage = null;

    /**
     * Clés et secrets des deux comptes Wave.
     *
     * Jamais pré-remplis, comme la clé Yango : vide à l'affichage signifie « on
     * garde celui déjà enregistré », et seul un aperçu masqué en filigrane dit
     * lequel est en place. Les deux comptes ont chacun leur panneau
     * et leur enregistrement — la boutique et la recharge n'ont aucune raison
     * de partager une clé, et les régler d'un seul geste invitait à les
     * confondre.
     */
    public string $waveShopApiKey = '';

    public string $waveShopWebhookSecret = '';

    public string $waveTopupApiKey = '';

    public string $waveTopupWebhookSecret = '';

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

    /**
     * Secrets relevés en clair pendant cette visite, par nom de champ.
     *
     * Rempli uniquement par `reveal()`, donc jamais au chargement : une clé ne
     * part vers le navigateur que sur un geste explicite, tracé au journal.
     * `$fieldsRevealed` n'est pas persisté entre navigations — quitter l'écran
     * remasque tout.
     *
     * @var array<string, string>
     */
    public array $revealedSecrets = [];

    /**
     * Les seuls secrets qu'un clic peut demander, et où les lire.
     *
     * Table blanche explicite : le navigateur envoie un nom de champ, jamais un
     * nom de réglage. Sans cela, `reveal('...')` deviendrait une lecture
     * arbitraire de la table `settings`.
     *
     * @return array<string, callable(): string>
     */
    private function revealableSecrets(): array
    {
        return [
            'fleetApiKey' => fn (): string => app(FleetSettings::class)->api_key,
            'waveShopApiKey' => fn (): string => app(WaveShopSettings::class)->api_key,
            'waveShopWebhookSecret' => fn (): string => app(WaveShopSettings::class)->webhook_secret,
            'waveTopupApiKey' => fn (): string => app(WaveTopupSettings::class)->api_key,
            'waveTopupWebhookSecret' => fn (): string => app(WaveTopupSettings::class)->webhook_secret,
        ];
    }

    /**
     * Renvoie un secret enregistré en clair, pour le champ nommé.
     *
     * Trois gardes, dans cet ordre : le champ doit figurer à la table blanche,
     * l'agent doit porter le droit `settings.reveal-secrets`, et la lecture est
     * journalisée avant de repartir. Le contenu n'est jamais écrit dans le
     * journal — seulement le fait qu'il a été relevé, et par qui.
     */
    public function reveal(string $field): void
    {
        $secrets = $this->revealableSecrets();

        if (! array_key_exists($field, $secrets)) {
            throw new AuthorizationException;
        }

        /** @var User $user */
        $user = auth()->user();

        if (! $user->can(BackOfficePermission::SettingsRevealSecrets->value)) {
            throw new AuthorizationException;
        }

        $secret = $secrets[$field]();

        if (blank($secret)) {
            return;
        }

        AuditLog::record(
            action: 'settings.secret_revealed',
            summary: "{$user->fullName()} a relevé en clair le secret « {$field} ».",
            by: $user,
            context: ['field' => $field],
        );

        $this->revealedSecrets[$field] = $secret;
    }

    /**
     * Remasque un secret : il quitte l'état du composant, donc la page.
     */
    public function conceal(string $field): void
    {
        unset($this->revealedSecrets[$field]);
    }

    public function saveWaveShop(WaveShopSettings $shop): void
    {
        $this->validate([
            // Facultatifs : laissés vides, les secrets déjà enregistrés sont
            // conservés.
            'waveShopApiKey' => 'nullable|string|max:255',
            'waveShopWebhookSecret' => 'nullable|string|max:255',
        ]);

        $this->storeWaveAccount($shop, $this->waveShopApiKey, $this->waveShopWebhookSecret);

        // Rien ne repart vers le navigateur une fois enregistré.
        $this->waveShopApiKey = '';
        $this->waveShopWebhookSecret = '';

        $this->dispatch('toast', message: __('backoffice.settings.wave_shop_saved'));
    }

    public function saveWaveTopup(WaveTopupSettings $topup): void
    {
        $this->validate([
            'waveTopupApiKey' => 'nullable|string|max:255',
            'waveTopupWebhookSecret' => 'nullable|string|max:255',
        ]);

        $this->storeWaveAccount($topup, $this->waveTopupApiKey, $this->waveTopupWebhookSecret);

        $this->waveTopupApiKey = '';
        $this->waveTopupWebhookSecret = '';

        $this->dispatch('toast', message: __('backoffice.settings.wave_topup_saved'));
    }

    /**
     * Enregistre un compte Wave sans toucher à l'autre.
     *
     * Un champ vide conserve la valeur déjà en place : le formulaire n'affiche
     * jamais les secrets, les effacer parce qu'on n'a rien saisi couperait
     * l'encaissement.
     */
    private function storeWaveAccount(WaveAccountSettings $settings, string $apiKey, string $webhookSecret): void
    {
        if (filled($apiKey)) {
            $settings->api_key = $apiKey;
        }

        if (filled($webhookSecret)) {
            $settings->webhook_secret = $webhookSecret;
        }

        $settings->save();
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

    public function render(FleetSettings $fleet, WaveShopSettings $shop, WaveTopupSettings $topup): View
    {
        /** @var view-string $view */
        $view = 'livewire.settings.index';

        return view($view, [
            // La clé elle-même ne quitte jamais le serveur : seule sa présence
            // est publiée, pour dire si la synchronisation peut tourner et si
            // le test a un sens.
            'fleetKeyStored' => filled($fleet->api_key),
            'waveShopKeyStored' => $shop->isConfigured(),
            'waveShopSecretStored' => filled($shop->webhook_secret),
            'waveTopupKeyStored' => $topup->isConfigured(),
            'waveTopupSecretStored' => filled($topup->webhook_secret),

            // Aperçus masqués : ils disent *laquelle* est en place — une clé de
            // test se distingue d'une clé de production, et les deux comptes
            // Wave l'un de l'autre. Passés en données de rendu et non en
            // propriétés liées : rien à renvoyer, donc rien à confondre avec
            // une saisie au moment d'enregistrer.
            'fleetKeyPreview' => SecretMask::preview($fleet->api_key),
            'waveShopKeyPreview' => SecretMask::preview($shop->api_key),
            'waveShopSecretPreview' => SecretMask::preview($shop->webhook_secret),
            'waveTopupKeyPreview' => SecretMask::preview($topup->api_key),
            'waveTopupSecretPreview' => SecretMask::preview($topup->webhook_secret),
        ]);
    }
}
