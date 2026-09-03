<div>
    <p class="text-xs font-semibold uppercase tracking-widest text-primary">
        {{ __('backoffice.support_team') }}
    </p>
    <h1 class="mt-2 text-3xl font-semibold leading-tight tracking-tight text-ink">
        {{ __('backoffice.manager_login') }}
    </h1>

    <div class="mt-6 h-px w-full bg-line"></div>

    <form wire:submit="login" class="mt-6 space-y-5">
        <x-field :label="__('backoffice.email')" name="email" id="email" type="email"
                 wire:model="email" autocomplete="username" required autofocus
                 placeholder="prenom.nom@atconfortplus.ci" />

        <x-field :label="__('backoffice.password')" name="password" id="password" type="password"
                 wire:model="password" autocomplete="current-password" required />

        <label for="remember" class="flex items-center gap-2.5 text-sm text-muted">
            <input wire:model="remember" id="remember" type="checkbox" class="size-4 rounded border-input text-primary">
            {{ __('backoffice.remember_me') }}
        </label>

        <x-button type="submit" class="w-full justify-between" target="login">
            {{ __('backoffice.sign_in') }}
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            <x-slot:loading>{{ __('backoffice.common.signing_in') }}</x-slot:loading>
        </x-button>
    </form>

    <p class="mt-6 text-xs text-muted">
        {{ __('backoffice.access_notice') }}
    </p>
</div>
