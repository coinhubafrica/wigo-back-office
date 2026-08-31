<div class="grid gap-5 lg:grid-cols-2">
    {{-- Barème OTP --}}
    <form wire:submit="saveOtp" class="rounded border border-line bg-card p-5">
        <h2 class="text-sm font-semibold text-ink">{{ __('backoffice.settings.otp_title') }}</h2>
        <p class="mt-1 text-xs text-muted">{{ __('backoffice.settings.otp_hint') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_length') }}</span>
                <input wire:model="otpLength" type="number" min="4" max="9"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpLength') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_ttl') }}</span>
                <input wire:model="otpTtlMinutes" type="number" min="1" max="60"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpTtlMinutes') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_max_attempts') }}</span>
                <input wire:model="otpMaxAttempts" type="number" min="1" max="20"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpMaxAttempts') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_lock') }}</span>
                <input wire:model="otpLockMinutes" type="number" min="1" max="1440"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpLockMinutes') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_throttle_sends') }}</span>
                <input wire:model="otpThrottleMaxSends" type="number" min="1" max="20"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpThrottleMaxSends') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_throttle_decay') }}</span>
                <input wire:model="otpThrottleDecayMinutes" type="number" min="1" max="1440"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpThrottleDecayMinutes') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block sm:col-span-2">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.otp_retention') }}</span>
                <input wire:model="otpRetentionDays" type="number" min="1" max="365"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('otpRetentionDays') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>
        </div>

        <button type="submit"
                class="mt-4 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
            {{ __('backoffice.settings.save') }}
        </button>
    </form>

    {{-- Plafonds de recharge --}}
    <form wire:submit="saveRecharge" class="rounded border border-line bg-card p-5">
        <h2 class="text-sm font-semibold text-ink">{{ __('backoffice.settings.recharge_title') }}</h2>
        <p class="mt-1 text-xs text-muted">{{ __('backoffice.settings.recharge_hint') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.recharge_min') }}</span>
                <input wire:model="rechargeMinAmount" type="number" min="100"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('rechargeMinAmount') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.recharge_max') }}</span>
                <input wire:model="rechargeMaxAmount" type="number"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('rechargeMaxAmount') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.recharge_daily_cap') }}</span>
                <input wire:model="rechargeDailyCap" type="number"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('rechargeDailyCap') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-ink">{{ __('backoffice.settings.recharge_balance_ttl') }}</span>
                <input wire:model="rechargeBalanceTtlMinutes" type="number" min="1" max="1440"
                       class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                @error('rechargeBalanceTtlMinutes') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
            </label>
        </div>

        <button type="submit"
                class="mt-4 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
            {{ __('backoffice.settings.save') }}
        </button>
    </form>

    {{-- Ce qui n'est délibérément pas modifiable ici. --}}
    <div class="rounded border border-dashed border-line bg-surface p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-ink">{{ __('backoffice.settings.env_title') }}</h2>
        <p class="mt-1 text-xs text-muted">{{ __('backoffice.settings.env_hint') }}</p>
        <ul class="mt-3 space-y-1 text-xs text-muted">
            <li>&middot; {{ __('backoffice.settings.env_otp_expose') }}</li>
            <li>&middot; {{ __('backoffice.settings.env_docs') }}</li>
            <li>&middot; {{ __('backoffice.settings.env_terms') }}</li>
        </ul>
    </div>
</div>
