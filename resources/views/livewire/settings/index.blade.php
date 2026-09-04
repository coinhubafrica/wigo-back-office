{{--
    Réglages métier. Deux formulaires, un par famille de réglages : chacun
    s'enregistre seul, pour qu'une erreur sur un plafond de recharge ne
    bloque pas la sauvegarde du barème OTP.

    Le bouton vit dans le pied du panneau et vise le formulaire par `form=`.
--}}
<div class="grid gap-5 lg:grid-cols-2">
    <x-panel :title="__('backoffice.settings.otp_title')" :subtitle="__('backoffice.settings.otp_hint')">
        <form id="settings-otp" wire:submit="saveOtp" class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('backoffice.settings.otp_length')" name="otpLength" type="number" wire:model="otpLength" min="4" max="9" />
            <x-field :label="__('backoffice.settings.otp_ttl')" name="otpTtlMinutes" type="number" wire:model="otpTtlMinutes" min="1" max="60" />
            <x-field :label="__('backoffice.settings.otp_max_attempts')" name="otpMaxAttempts" type="number" wire:model="otpMaxAttempts" min="1" max="20" />
            <x-field :label="__('backoffice.settings.otp_lock')" name="otpLockMinutes" type="number" wire:model="otpLockMinutes" min="1" max="1440" />
            <x-field :label="__('backoffice.settings.otp_throttle_sends')" name="otpThrottleMaxSends" type="number" wire:model="otpThrottleMaxSends" min="1" max="20" />
            <x-field :label="__('backoffice.settings.otp_throttle_decay')" name="otpThrottleDecayMinutes" type="number" wire:model="otpThrottleDecayMinutes" min="1" max="1440" />
            <x-field :label="__('backoffice.settings.otp_retention')" name="otpRetentionDays" type="number" wire:model="otpRetentionDays" min="1" max="365" class="sm:col-span-2" />
        </form>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-button type="submit" form="settings-otp" target="saveOtp">
                    {{ __('backoffice.settings.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </div>
        </x-slot:footer>
    </x-panel>

    <x-panel :title="__('backoffice.settings.recharge_title')" :subtitle="__('backoffice.settings.recharge_hint')">
        <form id="settings-recharge" wire:submit="saveRecharge" class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('backoffice.settings.recharge_min')" name="rechargeMinAmount" type="number" wire:model="rechargeMinAmount" min="100" />
            <x-field :label="__('backoffice.settings.recharge_max')" name="rechargeMaxAmount" type="number" wire:model="rechargeMaxAmount" />
            <x-field :label="__('backoffice.settings.recharge_daily_cap')" name="rechargeDailyCap" type="number" wire:model="rechargeDailyCap" />
            <x-field :label="__('backoffice.settings.recharge_balance_ttl')" name="rechargeBalanceTtlMinutes" type="number" wire:model="rechargeBalanceTtlMinutes" min="1" max="1440" />
        </form>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-button type="submit" form="settings-recharge" target="saveRecharge">
                    {{ __('backoffice.settings.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </div>
        </x-slot:footer>
    </x-panel>

    {{--
        Accès au parc Yango. La clé n'est jamais renvoyée au navigateur : le
        champ reste vide et ne s'enregistre que s'il est rempli.

        Le test est en lecture seule et porte sur la clé *enregistrée* — d'où
        l'ordre imposé à l'écran : enregistrer, puis tester.
    --}}
    <x-panel :title="__('backoffice.settings.fleet_title')" :subtitle="__('backoffice.settings.fleet_hint')" class="lg:col-span-2">
        <form id="settings-fleet" wire:submit="saveFleet" class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('backoffice.settings.fleet_base_url')" name="fleetBaseUrl" type="url" wire:model="fleetBaseUrl" placeholder="https://fleet-api.yango.tech" />
            <x-field :label="__('backoffice.settings.fleet_park_id')" name="fleetParkId" wire:model="fleetParkId" />
            {{-- L'aperçu masqué passe en `placeholder` : il s'affiche en filigrane,
                 n'est pas une valeur du champ (donc jamais renvoyé au serveur ni
                 confondu avec une saisie) et s'efface à la première frappe. --}}
            <x-field
                :label="__('backoffice.settings.fleet_api_key')"
                name="fleetApiKey"
                type="password"
                reveal="fleetApiKey"
                :revealed="$revealedSecrets['fleetApiKey'] ?? null"
                wire:model="fleetApiKey"
                autocomplete="off"
                :placeholder="$fleetKeyPreview"
                :hint="$fleetKeyStored ? __('backoffice.settings.key_replace_hint') : __('backoffice.settings.fleet_api_key_hint')"
                class="sm:col-span-2"
            />

            {{-- Seule l'absence est signalée : « une clé est enregistrée » répétait
                 l'aperçu et l'aide du champ, juste au-dessus. Ce qui reste dit la
                 conséquence — ce que le module ne peut pas faire sans clé. --}}
            @unless ($fleetKeyStored)
                <p class="sm:col-span-2 text-xs text-err-text">{{ __('backoffice.settings.fleet_api_key_missing') }}</p>
            @endunless
        </form>

        @if ($fleetTestMessage !== null)
            <p
                role="status"
                @class([
                    'mt-4 rounded px-3 py-2 text-xs',
                    'bg-ok-bg text-ok-text' => $fleetTestSucceeded,
                    'bg-err-bg text-err-text' => ! $fleetTestSucceeded,
                ])
            >{{ $fleetTestMessage }}</p>
        @endif

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-button variant="secondary" wire:click="testFleet" target="testFleet" :disabled="! $fleetKeyStored">
                    {{ __('backoffice.settings.fleet_test') }}
                    <x-slot:loading>{{ __('backoffice.settings.fleet_testing') }}</x-slot:loading>
                </x-button>
                <x-button type="submit" form="settings-fleet" target="saveFleet">
                    {{ __('backoffice.settings.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </div>
        </x-slot:footer>
    </x-panel>

    {{--
        Un panneau par compte Wave : la boutique et la recharge Yango
        s'ouvrent et se renouvellent séparément, les enregistrer d'un seul
        geste invitait à les confondre. Aucun champ n'est pré-rempli —
        laisser vide conserve ce qui est enregistré.
    --}}
    <x-panel :title="__('backoffice.settings.wave_shop_title')" :subtitle="__('backoffice.settings.wave_shop_hint')">
        <form id="settings-wave-shop" wire:submit="saveWaveShop" class="grid gap-4">
            <x-field
                :label="__('backoffice.settings.wave_api_key')"
                name="waveShopApiKey"
                type="password"
                reveal="waveShopApiKey"
                :revealed="$revealedSecrets['waveShopApiKey'] ?? null"
                wire:model="waveShopApiKey"
                autocomplete="off"
                :placeholder="$waveShopKeyPreview"
                :hint="$waveShopKeyStored ? __('backoffice.settings.key_replace_hint') : __('backoffice.settings.wave_secret_hint')"
            />
            <x-field
                :label="__('backoffice.settings.wave_webhook_secret')"
                name="waveShopWebhookSecret"
                type="password"
                reveal="waveShopWebhookSecret"
                :revealed="$revealedSecrets['waveShopWebhookSecret'] ?? null"
                wire:model="waveShopWebhookSecret"
                autocomplete="off"
                :placeholder="$waveShopSecretPreview"
                :hint="$waveShopSecretStored ? __('backoffice.settings.key_replace_hint') : null"
            />

            @unless ($waveShopKeyStored)
                <p class="text-xs text-err-text">{{ __('backoffice.settings.wave_key_missing') }}</p>
            @endunless
            @unless ($waveShopSecretStored)
                <p class="text-xs text-err-text">{{ __('backoffice.settings.wave_secret_missing') }}</p>
            @endunless

            <p class="text-xs text-muted">
                {{ __('backoffice.settings.wave_callback') }}
                <code class="break-all">{{ route('webhooks.wave', ['account' => 'shop']) }}</code>
            </p>
        </form>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-button type="submit" form="settings-wave-shop" target="saveWaveShop">
                    {{ __('backoffice.settings.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </div>
        </x-slot:footer>
    </x-panel>

    <x-panel :title="__('backoffice.settings.wave_topup_title')" :subtitle="__('backoffice.settings.wave_topup_hint')">
        <form id="settings-wave-topup" wire:submit="saveWaveTopup" class="grid gap-4">
            <x-field
                :label="__('backoffice.settings.wave_api_key')"
                name="waveTopupApiKey"
                type="password"
                reveal="waveTopupApiKey"
                :revealed="$revealedSecrets['waveTopupApiKey'] ?? null"
                wire:model="waveTopupApiKey"
                autocomplete="off"
                :placeholder="$waveTopupKeyPreview"
                :hint="$waveTopupKeyStored ? __('backoffice.settings.key_replace_hint') : __('backoffice.settings.wave_secret_hint')"
            />
            <x-field
                :label="__('backoffice.settings.wave_webhook_secret')"
                name="waveTopupWebhookSecret"
                type="password"
                reveal="waveTopupWebhookSecret"
                :revealed="$revealedSecrets['waveTopupWebhookSecret'] ?? null"
                wire:model="waveTopupWebhookSecret"
                autocomplete="off"
                :placeholder="$waveTopupSecretPreview"
                :hint="$waveTopupSecretStored ? __('backoffice.settings.key_replace_hint') : null"
            />

            @unless ($waveTopupKeyStored)
                <p class="text-xs text-err-text">{{ __('backoffice.settings.wave_key_missing') }}</p>
            @endunless
            @unless ($waveTopupSecretStored)
                <p class="text-xs text-err-text">{{ __('backoffice.settings.wave_secret_missing') }}</p>
            @endunless

            <p class="text-xs text-muted">
                {{ __('backoffice.settings.wave_callback') }}
                <code class="break-all">{{ route('webhooks.wave', ['account' => 'topup']) }}</code>
            </p>
        </form>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-button type="submit" form="settings-wave-topup" target="saveWaveTopup">
                    {{ __('backoffice.settings.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </div>
        </x-slot:footer>
    </x-panel>

    {{-- Ce qui n'est délibérément pas modifiable ici. --}}
    <section class="rounded border border-dashed border-line bg-surface p-5 lg:col-span-2" aria-labelledby="settings-env-title">
        <h2 id="settings-env-title" class="text-sm font-semibold text-ink">{{ __('backoffice.settings.env_title') }}</h2>
        <p class="mt-1 text-xs text-muted">{{ __('backoffice.settings.env_hint') }}</p>
        <ul class="mt-3 list-inside list-disc space-y-1 text-xs text-muted">
            <li>{{ __('backoffice.settings.env_otp_expose') }}</li>
            <li>{{ __('backoffice.settings.env_docs') }}</li>
            <li>{{ __('backoffice.settings.env_terms') }}</li>
        </ul>
    </section>
</div>
