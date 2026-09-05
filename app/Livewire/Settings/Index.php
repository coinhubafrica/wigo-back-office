<?php

namespace App\Livewire\Settings;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Services\Yango\YangoConnectionTester;
use App\Settings\OtpSettings;
use App\Settings\RechargeSettings;
use App\Settings\WaveAccountSettings;
use App\Settings\WaveShopSettings;
use App\Settings\WaveTopupSettings;
use App\Settings\YangoSettings;
use App\Support\SecretMask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
    use InteractsWithCurrentUser;

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

    public string $yangoBaseUrl = '';

    public string $yangoParkId = '';

    /**
     * Jamais pré-rempli avec la clé enregistrée : une clé en clair dans le HTML
     * de la page serait lisible par toute extension du navigateur. Vide à
     * l'affichage signifie « on garde celle déjà enregistrée ».
     *
     * Ce que le champ montre au repos est un *aperçu masqué* (`SecretMask`),
     * rendu en filigrane et non comme valeur : il nomme la clé en place sans
     * la publier, et disparaît dès la première frappe.
     */
    public string $yangoApiKey = '';

    /** Verdict du dernier test de connexion, le temps de la requête. */
    public int $yangoPageDelayMs = 250;

    public ?bool $yangoTestSucceeded = null;

    public ?string $yangoTestMessage = null;

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

    public function mount(OtpSettings $otp, RechargeSettings $recharge, YangoSettings $yango): void
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

        $this->yangoBaseUrl = $yango->base_url;
        $this->yangoParkId = $yango->park_id;
        $this->yangoPageDelayMs = $yango->page_delay_ms;
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
            'yangoApiKey' => fn (): string => app(YangoSettings::class)->api_key,
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

        $user = $this->actor();

        if (! $user->can(BackOfficePermission::SettingsRevealSecrets->value)) {
            throw new AuthorizationException;
        }

        $secret = $secrets[$field]();

        if (blank($secret)) {
            return;
        }

        AuditLog::record(
            action: AuditAction::SettingsSecretRevealed->value,
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
        Gate::authorize('manageSettings');

        $this->validate([
            // Facultatifs : laissés vides, les secrets déjà enregistrés sont
            // conservés.
            'waveShopApiKey' => 'nullable|string|max:255',
            'waveShopWebhookSecret' => 'nullable|string|max:255',
        ]);

        $replaced = $this->replacedSecretFields([
            'api_key' => $this->waveShopApiKey,
            'webhook_secret' => $this->waveShopWebhookSecret,
        ]);

        $this->storeWaveAccount($shop, $this->waveShopApiKey, $this->waveShopWebhookSecret);

        // Rien ne repart vers le navigateur une fois enregistré.
        $this->waveShopApiKey = '';
        $this->waveShopWebhookSecret = '';

        if ($replaced !== []) {
            AuditLog::record(
                action: AuditAction::SettingsWaveShopUpdated->value,
                summary: "{$this->actor()->fullName()} a remplacé les clés du compte Wave boutique.",
                by: $this->actor(),
                context: ['fields' => $replaced],
            );
        }

        $this->dispatch('toast', message: __('backoffice.settings.wave_shop_saved'));
    }

    public function saveWaveTopup(WaveTopupSettings $topup): void
    {
        Gate::authorize('manageSettings');

        $this->validate([
            'waveTopupApiKey' => 'nullable|string|max:255',
            'waveTopupWebhookSecret' => 'nullable|string|max:255',
        ]);

        $replaced = $this->replacedSecretFields([
            'api_key' => $this->waveTopupApiKey,
            'webhook_secret' => $this->waveTopupWebhookSecret,
        ]);

        $this->storeWaveAccount($topup, $this->waveTopupApiKey, $this->waveTopupWebhookSecret);

        $this->waveTopupApiKey = '';
        $this->waveTopupWebhookSecret = '';

        if ($replaced !== []) {
            AuditLog::record(
                action: AuditAction::SettingsWaveTopupUpdated->value,
                summary: "{$this->actor()->fullName()} a remplacé les clés du compte Wave recharge.",
                by: $this->actor(),
                context: ['fields' => $replaced],
            );
        }

        $this->dispatch('toast', message: __('backoffice.settings.wave_topup_saved'));
    }

    /**
     * Noms des champs secrets effectivement remplacés — jamais leurs valeurs.
     *
     * Même règle que `settings.secret_revealed` : le journal dit *qu'une* clé a
     * changé et laquelle, jamais ce qu'elle vaut. Une clé recopiée dans
     * `context` serait lisible par quiconque atteint l'écran d'audit, et
     * l'export l'emporterait dans un fichier — l'outil de surveillance
     * deviendrait le point de fuite qu'il est censé surveiller.
     *
     * Seuls les champs remplis y figurent : enregistrer avec le champ vide
     * conserve la clé en place (cf. `storeWaveAccount`) et ne doit donc rien
     * annoncer.
     *
     * @param  array<string, string>  $candidates
     * @return list<string>
     */
    private function replacedSecretFields(array $candidates): array
    {
        return array_keys(array_filter(
            $candidates,
            fn (string $value): bool => filled($value),
        ));
    }

    /**
     * Journalise un réglage non secret, en ne gardant que ce qui a bougé.
     *
     * Un barème réenregistré à l'identique n'écrit rien : le journal doit dire
     * ce qui a changé, pas qu'on a cliqué sur « Enregistrer ».
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    private function recordSettingChange(AuditAction $action, string $summary, array $before, array $after): void
    {
        $changed = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changed[$field.'_before'] = $before[$field] ?? null;
                $changed[$field.'_after'] = $value;
            }
        }

        if ($changed === []) {
            return;
        }

        AuditLog::record(
            action: $action->value,
            summary: $summary,
            by: $this->actor(),
            context: $changed,
        );
    }

    /**
     * @return array<string, int>
     */
    private function otpValues(OtpSettings $otp): array
    {
        return [
            'length' => $otp->length,
            'ttl_minutes' => $otp->ttl_minutes,
            'max_attempts' => $otp->max_attempts,
            'lock_minutes' => $otp->lock_minutes,
            'throttle_max_sends' => $otp->throttle_max_sends,
            'throttle_decay_minutes' => $otp->throttle_decay_minutes,
            'retention_days' => $otp->retention_days,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function rechargeValues(RechargeSettings $recharge): array
    {
        return [
            'min_amount' => $recharge->min_amount,
            'max_amount' => $recharge->max_amount,
            'daily_cap' => $recharge->daily_cap,
            'balance_ttl_minutes' => $recharge->balance_ttl_minutes,
        ];
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
        Gate::authorize('manageSettings');

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

        /*
        | `max_attempts`, `lock_minutes` et les deux `throttle_*` sont la
        | défense anti-force-brute du mobile : les desserrer est une décision de
        | sécurité. Rien ici n'est secret, donc l'avant/après est journalisé —
        | c'est le diff qui a du sens, pas la valeur seule.
        */
        $before = $this->otpValues($otp);

        $otp->length = $this->otpLength;
        $otp->ttl_minutes = $this->otpTtlMinutes;
        $otp->max_attempts = $this->otpMaxAttempts;
        $otp->lock_minutes = $this->otpLockMinutes;
        $otp->throttle_max_sends = $this->otpThrottleMaxSends;
        $otp->throttle_decay_minutes = $this->otpThrottleDecayMinutes;
        $otp->retention_days = $this->otpRetentionDays;
        $otp->save();

        $this->recordSettingChange(
            AuditAction::SettingsOtpUpdated,
            "{$this->actor()->fullName()} a modifié le barème OTP.",
            $before,
            $this->otpValues($otp),
        );

        $this->dispatch('toast', message: __('backoffice.settings.otp_saved'));
    }

    public function saveRecharge(RechargeSettings $recharge): void
    {
        Gate::authorize('manageSettings');

        $this->validate([
            'rechargeMinAmount' => 'required|integer|min:100',
            'rechargeMaxAmount' => 'required|integer|gt:rechargeMinAmount',
            // Le plafond journalier borne le cumul : en dessous d'une recharge
            // unitaire maximale, la seconde recharge du jour serait toujours
            // refusée.
            'rechargeDailyCap' => 'required|integer|gte:rechargeMaxAmount',
            'rechargeBalanceTtlMinutes' => 'required|integer|min:1|max:1440',
        ]);

        // Ces bornes décident de l'argent qu'un conducteur peut engager.
        $before = $this->rechargeValues($recharge);

        $recharge->min_amount = $this->rechargeMinAmount;
        $recharge->max_amount = $this->rechargeMaxAmount;
        $recharge->daily_cap = $this->rechargeDailyCap;
        $recharge->balance_ttl_minutes = $this->rechargeBalanceTtlMinutes;
        $recharge->save();

        $this->recordSettingChange(
            AuditAction::SettingsRechargeUpdated,
            "{$this->actor()->fullName()} a modifié les plafonds de recharge.",
            $before,
            $this->rechargeValues($recharge),
        );

        $this->dispatch('toast', message: __('backoffice.settings.recharge_saved'));
    }

    public function saveYango(YangoSettings $yango): void
    {
        Gate::authorize('manageSettings');

        $this->validate([
            'yangoBaseUrl' => 'required|url',
            'yangoParkId' => 'required|string|max:255',
            // Zéro désactive l'espacement ; le plafond garde une passe finie.
            'yangoPageDelayMs' => 'required|integer|min:0|max:10000',
            // Facultative : laissée vide, la clé déjà enregistrée est conservée.
            'yangoApiKey' => 'nullable|string|max:255',
        ]);

        // Avant/après de ce qui n'est pas secret : l'adresse du service et le
        // parc identifient *quel* parc on crédite, et détourner l'adresse est
        // une voie d'exfiltration. La clé, elle, n'est citée que par son nom.
        $before = [
            'base_url' => $yango->base_url,
            'park_id' => $yango->park_id,
            'page_delay_ms' => $yango->page_delay_ms,
        ];

        $yango->base_url = $this->yangoBaseUrl;
        $yango->park_id = $this->yangoParkId;
        $yango->page_delay_ms = $this->yangoPageDelayMs;

        if (filled($this->yangoApiKey)) {
            $yango->api_key = $this->yangoApiKey;
        }

        $yango->save();

        // La clé ne repart pas vers le navigateur une fois enregistrée.
        $replaced = $this->replacedSecretFields(['api_key' => $this->yangoApiKey]);
        $this->yangoApiKey = '';
        $this->yangoTestSucceeded = null;
        $this->yangoTestMessage = null;

        AuditLog::record(
            action: AuditAction::SettingsYangoUpdated->value,
            summary: "{$this->actor()->fullName()} a modifié l'accès au parc Yango.",
            by: $this->actor(),
            context: array_filter([
                'base_url' => $before['base_url'] === $yango->base_url ? null : $yango->base_url,
                'park_id' => $before['park_id'] === $yango->park_id ? null : $yango->park_id,
                'page_delay_ms' => $before['page_delay_ms'] === $yango->page_delay_ms ? null : $yango->page_delay_ms,
                'fields' => $replaced === [] ? null : $replaced,
            ]),
        );

        $this->dispatch('toast', message: __('backoffice.settings.yango_saved'));
    }

    /**
     * Teste les identifiants enregistrés en lecture seule : un conducteur
     * demandé, rien d'écrit. Enregistrer d'abord, tester ensuite.
     */
    public function testYango(YangoConnectionTester $tester): void
    {
        Gate::authorize('manageSettings');

        $result = $tester->test();

        $this->yangoTestSucceeded = $result->succeeded;

        $this->yangoTestMessage = match (true) {
            $result->succeeded && $result->empty => (string) __('backoffice.settings.yango_test_empty'),
            $result->succeeded => (string) __('backoffice.settings.yango_test_ok'),
            $result->status !== null => (string) __('backoffice.settings.yango_test_failed_status', [
                'status' => $result->status,
                'message' => $result->message ?? '',
            ]),
            default => $result->message,
        };
    }

    public function render(YangoSettings $yango, WaveShopSettings $shop, WaveTopupSettings $topup): View
    {
        /** @var view-string $view */
        $view = 'livewire.settings.index';

        return view($view, [
            // La clé elle-même ne quitte jamais le serveur : seule sa présence
            // est publiée, pour dire si la synchronisation peut tourner et si
            // le test a un sens.
            'yangoKeyStored' => filled($yango->api_key),
            'waveShopKeyStored' => $shop->isConfigured(),
            'waveShopSecretStored' => filled($shop->webhook_secret),
            'waveTopupKeyStored' => $topup->isConfigured(),
            'waveTopupSecretStored' => filled($topup->webhook_secret),

            // Aperçus masqués : ils disent *laquelle* est en place — une clé de
            // test se distingue d'une clé de production, et les deux comptes
            // Wave l'un de l'autre. Passés en données de rendu et non en
            // propriétés liées : rien à renvoyer, donc rien à confondre avec
            // une saisie au moment d'enregistrer.
            'yangoKeyPreview' => SecretMask::preview($yango->api_key),
            'waveShopKeyPreview' => SecretMask::preview($shop->api_key),
            'waveShopSecretPreview' => SecretMask::preview($shop->webhook_secret),
            'waveTopupKeyPreview' => SecretMask::preview($topup->api_key),
            'waveTopupSecretPreview' => SecretMask::preview($topup->webhook_secret),
        ]);
    }
}
