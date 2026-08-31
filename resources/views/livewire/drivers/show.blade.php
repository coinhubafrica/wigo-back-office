<div class="flex max-w-[720px] flex-col gap-4">
    <a href="{{ route(\App\Enums\BackOfficeModule::Drivers->route()) }}" wire:navigate
       class="flex w-fit items-center gap-1.5 rounded border border-line bg-card px-3 py-2 text-sm font-semibold text-ink hover:bg-surface">
        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        {{ __('backoffice.drivers.back_to_list') }}
    </a>

    <div class="flex items-start gap-3.5 rounded border border-line bg-card p-5">
        @if ($driver->photo_url)
            <img src="{{ route('bo.drivers.photo', $driver) }}" alt="{{ $driver->fullName() }}"
                 class="size-12 shrink-0 rounded object-cover">
        @else
            <span class="flex size-12 shrink-0 items-center justify-center rounded bg-primary-tint text-base font-semibold text-primary-text">
                {{ $driver->initials() }}
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold text-ink">{{ $driver->fullName() }}</h2>
            <p class="text-sm text-muted">{{ $driver->phone }}</p>
        </div>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $driver->status->badgeClasses() }}">
            {{ $driver->status->label() }}
        </span>
    </div>

    <div class="rounded border border-line bg-card">
        <h2 class="border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-wide text-muted">
            {{ __('backoffice.drivers.identity_and_vehicle') }}
        </h2>
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
            @php $currentCnps = $cnps['current']; @endphp
            <p @class([
                'mt-2 text-xl font-semibold',
                'text-ink' => $currentCnps['declared_amount'] > 0,
                'text-muted' => $currentCnps['declared_amount'] === 0,
            ])>
                {{ $currentCnps['declared_amount'] > 0 ? number_format($currentCnps['declared_amount'], 0, ',', ' ').' FCFA' : '—' }}
            </p>
            <p class="mt-0.5 text-xs text-muted">
                {{ \App\Enums\CnpsMonthStatus::from($currentCnps['status'])->label() }} · {{ $currentCnps['label'] }}
            </p>
        </div>
    </div>

    <div class="rounded border border-line bg-card p-5">
        @if ($driver->isSuspended())
            <div>
                <p class="text-sm text-ink">{{ __('backoffice.drivers.suspension_reason') }}: <b>{{ $driver->suspension_reason }}</b></p>
                <button wire:click="confirmReactivate"
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
                       class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
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

    {{-- Suivi CNPS : le même relevé que celui vu par le conducteur dans
         l'application, pour qu'agent et conducteur parlent des mêmes chiffres.
         Rien à valider ici : la déclaration fait foi telle quelle. --}}
    <div class="rounded border border-line bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3.5">
            <h2 class="text-sm font-semibold text-ink">{{ __('backoffice.cnps.panel_title') }}</h2>
            <div class="text-right">
                @if ($cnps['reference'] === null)
                    <p class="text-xs text-muted">{{ __('backoffice.cnps.reference_none') }}</p>
                @else
                    <p class="text-xs uppercase tracking-wide text-muted">{{ __('backoffice.cnps.reference_amount') }}</p>
                    <p class="text-sm font-semibold text-ink">
                        {{ number_format($cnps['reference']['amount'], 0, ',', ' ') }} FCFA
                        <span class="ml-1 text-xs font-normal text-muted">
                            {{ $cnps['reference']['set_by'] === 'agent'
                                ? __('backoffice.cnps.set_by_agent')
                                : __('backoffice.cnps.set_by_driver') }}
                        </span>
                    </p>
                @endif
            </div>
        </div>

        <div class="border-b border-line px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.current_month') }} — {{ $cnps['current']['label'] }}</p>
            <div class="mt-2 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <p class="text-2xl font-semibold text-ink">
                    {{ number_format($cnps['current']['declared_amount'], 0, ',', ' ') }}
                    <span class="text-sm font-medium text-muted">
                        / {{ $cnps['current']['reference_amount'] === null ? '—' : number_format($cnps['current']['reference_amount'], 0, ',', ' ') }} FCFA
                    </span>
                </p>
                @php $currentStatus = \App\Enums\CnpsMonthStatus::from($cnps['current']['status']); @endphp
                <span @class([
                    'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    'bg-ok-bg text-ok-text' => $currentStatus === \App\Enums\CnpsMonthStatus::Paid,
                    'bg-warn-bg text-warn-text' => $currentStatus === \App\Enums\CnpsMonthStatus::Partial,
                    'bg-err-bg text-err-text' => $currentStatus === \App\Enums\CnpsMonthStatus::Late,
                    'bg-neutral-bg text-neutral-text' => $currentStatus === \App\Enums\CnpsMonthStatus::Pending,
                ])>{{ $currentStatus->label() }}</span>
                @if ($cnps['current']['remaining'] > 0)
                    <span class="text-xs text-muted">
                        {{ __('backoffice.cnps.remaining') }} :
                        <b class="text-ink">{{ number_format($cnps['current']['remaining'], 0, ',', ' ') }} FCFA</b>
                    </span>
                @endif
            </div>
            @if ($cnps['current']['reference_amount'] !== null)
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-surface"
                     role="progressbar" aria-valuenow="{{ (int) $cnps['current']['progress'] }}"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ __('backoffice.cnps.current_month') }}">
                    <div class="h-full rounded-full bg-ok-text" style="width: {{ $cnps['current']['progress'] }}%"></div>
                </div>
            @endif
        </div>

        <div class="px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.history_title') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full border-collapse">
                    <tbody>
                        @foreach ($cnps['history'] as $month)
                            <tr class="border-b border-line last:border-0">
                                <td class="py-2 pr-4 text-[13px] text-ink">{{ $month['label'] }}</td>
                                <td class="py-2 pr-4 text-right text-[13px]">
                                    <b @class([
                                        'font-semibold',
                                        'text-ink' => $month['declared_amount'] > 0,
                                        'text-muted' => $month['declared_amount'] === 0,
                                    ])>{{ $month['declared_amount'] > 0 ? number_format($month['declared_amount'], 0, ',', ' ') : '—' }}</b>
                                    <span class="text-muted"> / {{ $month['reference_amount'] === null ? '—' : number_format($month['reference_amount'], 0, ',', ' ') }}</span>
                                </td>
                                <td class="py-2 pr-4">
                                    @php $monthStatus = \App\Enums\CnpsMonthStatus::from($month['status']); @endphp
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                        'bg-ok-bg text-ok-text' => $monthStatus === \App\Enums\CnpsMonthStatus::Paid,
                                        'bg-warn-bg text-warn-text' => $monthStatus === \App\Enums\CnpsMonthStatus::Partial,
                                        'bg-err-bg text-err-text' => $monthStatus === \App\Enums\CnpsMonthStatus::Late,
                                        'bg-neutral-bg text-neutral-text' => $monthStatus === \App\Enums\CnpsMonthStatus::Pending,
                                    ])>{{ $monthStatus->label() }}</span>
                                </td>
                                <td class="py-2 text-right text-[11px] text-muted">
                                    @foreach ($month['declarations'] as $declaration)
                                        <span class="ml-2 whitespace-nowrap">
                                            {{ number_format($declaration['declared_amount'], 0, ',', ' ') }}
                                            {{ __('backoffice.cnps.payment_on', ['date' => \Illuminate\Support\Carbon::parse($declaration['payment_date'])->translatedFormat('j M')]) }}
                                            @if ($declaration['has_proof'])
                                                {{-- Trombone en SVG et non en emoji : le rendu
                                                     variait selon l'OS et `title` seul n'est pas
                                                     un nom accessible fiable. --}}
                                                <svg class="inline-block size-3 align-text-bottom text-muted" viewBox="0 0 24 24"
                                                     fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                                     role="img" aria-label="{{ __('backoffice.cnps.with_proof') }}">
                                                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                                </svg>
                                            @endif
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($confirmingReactivation)
        <x-modal close="cancelReactivate" max-width="max-w-sm"
                 :label="__('backoffice.drivers.confirm_reactivate')">
            <div class="px-5 pb-4 pt-5">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.drivers.reactivate') }}</p>
                <p class="mt-1.5 text-sm text-muted">{{ __('backoffice.drivers.confirm_reactivate') }}</p>
            </div>
            <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                <button wire:click="cancelReactivate" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.drivers.cancel') }}
                </button>
                <button wire:click="reactivate" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.drivers.reactivate') }}
                </button>
            </div>
        </x-modal>
    @endif
</div>
