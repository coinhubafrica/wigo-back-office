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
