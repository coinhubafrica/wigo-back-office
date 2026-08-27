<div class="flex flex-col gap-4" style="max-width: 720px;">
    <a href="{{ route(\App\Enums\BackOfficeModule::Drivers->route()) }}" wire:navigate
       class="flex w-fit items-center gap-1.5 rounded border border-line bg-card px-3 py-2 text-sm font-semibold text-ink hover:bg-surface">
        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        {{ __('backoffice.drivers.back_to_list') }}
    </a>

    <div class="flex items-start gap-3.5 rounded border border-line bg-card p-5">
        <span class="flex size-12 shrink-0 items-center justify-center rounded bg-primary-tint text-base font-semibold text-primary-text">
            {{ $driver->initials() }}
        </span>
        <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold text-ink">{{ $driver->fullName() }}</h2>
            <p class="text-sm text-muted">{{ $driver->phone }}</p>
        </div>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $driver->status->badgeClasses() }}">
            {{ $driver->status->label() }}
        </span>
    </div>

    @if ($driver->hasPhotoPendingModeration())
        <div class="rounded border border-warn-text/20 bg-warn-bg p-4 text-sm text-ink">
            {{ __('backoffice.drivers.photo_pending') }}
            <div class="mt-3 flex gap-2">
                <button wire:click="approvePhoto" class="rounded bg-ok-text px-3.5 py-2 text-xs font-semibold text-white">
                    {{ __('backoffice.drivers.approve') }}
                </button>
                <button wire:click="rejectPhoto" class="rounded border border-err-text px-3.5 py-2 text-xs font-semibold text-err-text">
                    {{ __('backoffice.drivers.reject') }}
                </button>
            </div>
        </div>
    @endif

    <div class="rounded border border-line bg-card">
        <div class="border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-wide text-muted">
            {{ __('backoffice.drivers.identity_and_vehicle') }}
        </div>
        <div class="px-5">
            <div class="flex justify-between border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.drivers.vehicle') }}</span>
                <b class="text-ink">
                    @if ($driver->vehicle)
                        {{ $driver->vehicle->plate_number }} — {{ $driver->vehicle->brand }} {{ $driver->vehicle->model }}
                    @else
                        {{ __('backoffice.drivers.no_vehicle') }}
                    @endif
                </b>
            </div>
            <div class="flex justify-between border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.drivers.license_number') }}</span>
                <b class="text-ink">{{ $driver->license_number ?? '—' }}</b>
            </div>
            <div class="flex justify-between py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.drivers.phone') }}</span>
                <b class="text-ink">{{ $driver->phone }}</b>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.trips_this_week') }}</p>
            <p class="mt-2 text-xl font-semibold text-muted">—</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.yango_balance') }}</p>
            <p class="mt-2 text-xl font-semibold text-muted">—</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.cnps_this_month') }}</p>
            <p class="mt-2 text-xl font-semibold text-muted">—</p>
        </div>
    </div>

    <div class="rounded border border-line bg-card p-5">
        @if ($driver->isSuspended())
            <div>
                <p class="text-sm text-ink">{{ __('backoffice.drivers.suspension_reason') }}: <b>{{ $driver->suspension_reason }}</b></p>
                <button wire:click="reactivate" wire:confirm="{{ __('backoffice.drivers.confirm_reactivate') }}"
                        class="mt-3 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.drivers.reactivate') }}
                </button>
            </div>
        @elseif ($showSuspendForm)
            <form wire:submit="suspend" class="space-y-3">
                <label for="suspensionReason" class="block text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ __('backoffice.drivers.suspension_reason') }}
                </label>
                <input wire:model="suspensionReason" id="suspensionReason" type="text" required
                       class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                @error('suspensionReason')
                    <p class="text-sm text-err-text">{{ $message }}</p>
                @enderror
                <div class="flex gap-2">
                    <button type="submit" class="rounded bg-err-text px-4 py-2 text-sm font-semibold text-white">
                        {{ __('backoffice.drivers.confirm_suspend') }}
                    </button>
                    <button type="button" wire:click="$toggle('showSuspendForm')" class="rounded border border-line px-4 py-2 text-sm text-muted hover:bg-surface">
                        {{ __('backoffice.drivers.cancel') }}
                    </button>
                </div>
            </form>
        @else
            <button wire:click="$toggle('showSuspendForm')" class="rounded border border-err-text px-4 py-2 text-sm font-semibold text-err-text">
                {{ __('backoffice.drivers.suspend') }}
            </button>
        @endif
    </div>
</div>
