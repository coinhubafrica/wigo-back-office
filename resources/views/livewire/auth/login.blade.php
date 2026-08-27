<div>
    <p class="text-xs font-semibold uppercase tracking-widest text-primary">
        {{ __('backoffice.support_team') }}
    </p>
    <h1 class="mt-2 text-3xl font-semibold leading-tight text-ink">
        {{ __('backoffice.manager_login') }}
    </h1>

    <div class="mt-6 h-px w-full bg-input"></div>

    <form wire:submit="login" class="mt-6 space-y-5">
        <div>
            <label for="email" class="block text-xs font-semibold uppercase tracking-wide text-muted">
                {{ __('backoffice.email') }}
            </label>
            <input wire:model="email" id="email" type="email" name="email"
                   autocomplete="username" required autofocus
                   class="mt-1.5 block w-full rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary focus:outline-none"
                   placeholder="prenom.nom@atconfortplus.ci">
            @error('email')
                <p class="mt-1.5 text-sm text-err-text">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-semibold uppercase tracking-wide text-muted">
                {{ __('backoffice.password') }}
            </label>
            <input wire:model="password" id="password" type="password" name="password"
                   autocomplete="current-password" required
                   class="mt-1.5 block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
            @error('password')
                <p class="mt-1.5 text-sm text-err-text">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-muted">
            <input wire:model="remember" type="checkbox"
                   class="rounded border-input text-primary focus:ring-primary">
            {{ __('backoffice.remember_me') }}
        </label>

        <button type="submit"
                class="flex w-full items-center justify-between rounded bg-primary px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-hover active:bg-primary-active disabled:opacity-60"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">{{ __('backoffice.sign_in') }}</span>
            <span wire:loading wire:target="login">Connexion…</span>
            <svg wire:loading.remove wire:target="login" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 12h14M13 6l6 6-6 6"/>
            </svg>
        </button>
    </form>

    <p class="mt-6 text-xs text-muted">
        {{ __('backoffice.access_notice') }}
    </p>
</div>
