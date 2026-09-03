<div class="flex max-w-[860px] flex-col gap-4">
    <x-slot:back>
        <x-back-link :href="route(\App\Enums\BackOfficeModule::Drivers->route())">{{ __('backoffice.drivers.back_to_list') }}</x-back-link>
    </x-slot:back>

    <x-panel>
        <div class="flex flex-wrap items-center gap-3.5">
            <x-avatar size="lg" :initials="$driver->initials()"
                      :src="$driver->photo_url ? route('bo.drivers.photo', $driver) : null" />
            <div class="min-w-0 flex-1">
                <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-ink">
                    <span class="truncate">{{ $driver->fullName() }}</span>
                    <x-badge :classes="$driver->status->badgeClasses()">{{ $driver->status->label() }}</x-badge>
                </h2>
                <p class="font-mono text-sm text-muted">{{ $driver->phone }}</p>
            </div>

            {{-- Suspendre ouvre un parcours destructeur (liseré rouge) ; la
                 confirmation, elle, est le bouton plein. --}}
            @if ($driver->isSuspended())
                <x-button wire:click="confirmReactivate" target="confirmReactivate">{{ __('backoffice.drivers.reactivate') }}</x-button>
            @elseif (! $showSuspendForm)
                <x-button variant="danger-outline" wire:click="$toggle('showSuspendForm')">{{ __('backoffice.drivers.suspend') }}</x-button>
            @endif
        </div>
    </x-panel>

    @if ($driver->isSuspended())
        <x-banner tone="warn" :title="__('backoffice.drivers.suspension_reason')">
            {{ $driver->suspension_reason }}
        </x-banner>
    @elseif ($showSuspendForm)
        <x-panel :title="__('backoffice.drivers.suspend')">
            <form id="driver-suspend" wire:submit="suspend">
                <x-field :label="__('backoffice.drivers.suspension_reason')" name="suspensionReason"
                         wire:model="suspensionReason" required autofocus />
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2.5">
                    <x-button variant="secondary" wire:click="$toggle('showSuspendForm')">{{ __('backoffice.drivers.cancel') }}</x-button>
                    <x-button variant="danger" type="submit" form="driver-suspend" target="suspend">
                        {{ __('backoffice.drivers.confirm_suspend') }}
                        <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                    </x-button>
                </div>
            </x-slot:footer>
        </x-panel>
    @endif

    <x-panel :title="__('backoffice.drivers.identity_and_vehicle')">
        <x-dl cols="3">
            <x-dl-item :term="__('backoffice.drivers.vehicle')">
                @if ($driver->vehicle)
                    {{ $driver->vehicle->plate_number }} — {{ $driver->vehicle->brand }} {{ $driver->vehicle->model }}
                @else
                    {{ __('backoffice.drivers.no_vehicle') }}
                @endif
            </x-dl-item>
            <x-dl-item :term="__('backoffice.drivers.license_number')" mono>{{ $driver->license_number ?? '—' }}</x-dl-item>
            <x-dl-item :term="__('backoffice.drivers.phone')" mono>{{ $driver->phone }}</x-dl-item>
        </x-dl>
    </x-panel>

    {{-- Courses et solde attendent la synchronisation Fleet : un tiret, pas un
         chiffre inventé (cf. .ai/rules/drivers.md). --}}
    @php $currentCnps = $cnps['current']; @endphp
    <div class="grid gap-3 sm:grid-cols-3">
        <x-kpi-card :label="__('backoffice.drivers.trips_this_week')" value="—" />
        <x-kpi-card :label="__('backoffice.drivers.yango_balance')" value="—" />
        <x-kpi-card :label="__('backoffice.drivers.cnps_this_month')"
                    :value="$currentCnps['declared_amount'] > 0 ? number_format($currentCnps['declared_amount'], 0, ',', ' ') : '—'"
                    :unit="$currentCnps['declared_amount'] > 0 ? 'FCFA' : null"
                    :hint="\App\Enums\CnpsMonthStatus::from($currentCnps['status'])->label().' · '.$currentCnps['label']" />
    </div>

    {{-- Suivi CNPS : le même relevé que celui vu par le conducteur dans
         l'application, pour qu'agent et conducteur parlent des mêmes chiffres.
         Rien à valider ici : la déclaration fait foi telle quelle. --}}
    <x-panel :title="__('backoffice.cnps.panel_title')" flush>
        <x-slot:actions>
            <div class="text-right">
                @if ($cnps['reference'] === null)
                    <p class="text-xs text-muted">{{ __('backoffice.cnps.reference_none') }}</p>
                @else
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.reference_amount') }}</p>
                    <p class="text-sm font-semibold text-ink tabular-nums">
                        {{ number_format($cnps['reference']['amount'], 0, ',', ' ') }} FCFA
                        <span class="ml-1 text-xs font-normal text-muted">
                            {{ $cnps['reference']['set_by'] === 'agent'
                                ? __('backoffice.cnps.set_by_agent')
                                : __('backoffice.cnps.set_by_driver') }}
                        </span>
                    </p>
                @endif
            </div>
        </x-slot:actions>

        <div class="border-b border-line px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.current_month') }} — {{ $cnps['current']['label'] }}</p>
            <div class="mt-2 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <p class="text-2xl font-semibold text-ink tabular-nums">
                    {{ number_format($cnps['current']['declared_amount'], 0, ',', ' ') }}
                    <span class="text-sm font-medium text-muted">
                        / {{ $cnps['current']['reference_amount'] === null ? '—' : number_format($cnps['current']['reference_amount'], 0, ',', ' ') }} FCFA
                    </span>
                </p>
                @php $currentStatus = \App\Enums\CnpsMonthStatus::from($cnps['current']['status']); @endphp
                <x-badge :classes="$currentStatus->badgeClasses()">{{ $currentStatus->label() }}</x-badge>
                @if ($cnps['current']['remaining'] > 0)
                    <span class="text-xs text-muted">
                        {{ __('backoffice.cnps.remaining') }} :
                        <b class="text-ink tabular-nums">{{ number_format($cnps['current']['remaining'], 0, ',', ' ') }} FCFA</b>
                    </span>
                @endif
            </div>
            @if ($cnps['current']['reference_amount'] !== null)
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-bg"
                     role="progressbar" aria-valuenow="{{ (int) $cnps['current']['progress'] }}"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ __('backoffice.cnps.current_month') }}">
                    <div class="h-full rounded-full bg-ok-text transition-[width]" style="width: {{ $cnps['current']['progress'] }}%"></div>
                </div>
            @endif
        </div>

        <x-table>
            <x-slot:head>
                <x-th>{{ __('backoffice.cnps.column_month') }}</x-th>
                <x-th align="right">{{ __('backoffice.cnps.column_declared') }}</x-th>
                <x-th>{{ __('backoffice.cnps.column_state') }}</x-th>
                <x-th align="right">{{ __('backoffice.cnps.column_payments') }}</x-th>
            </x-slot:head>
            @foreach ($cnps['history'] as $month)
                @php $monthStatus = \App\Enums\CnpsMonthStatus::from($month['status']); @endphp
                <tr wire:key="cnps-month-{{ $month['label'] }}" class="transition-colors hover:bg-surface">
                    <x-td nowrap>{{ $month['label'] }}</x-td>
                    <x-td align="right" nowrap>
                        <b @class(['font-semibold tabular-nums', 'text-ink' => $month['declared_amount'] > 0, 'text-muted' => $month['declared_amount'] === 0])>{{ $month['declared_amount'] > 0 ? number_format($month['declared_amount'], 0, ',', ' ') : '—' }}</b>
                        <span class="text-muted tabular-nums"> / {{ $month['reference_amount'] === null ? '—' : number_format($month['reference_amount'], 0, ',', ' ') }}</span>
                    </x-td>
                    <x-td><x-badge :classes="$monthStatus->badgeClasses()">{{ $monthStatus->label() }}</x-badge></x-td>
                    <x-td align="right" muted class="text-[11px]">
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
                    </x-td>
                </tr>
            @endforeach
        </x-table>
    </x-panel>

    @if ($confirmingReactivation)
        <x-confirm close="cancelReactivate" action="reactivate"
                   :title="__('backoffice.drivers.reactivate')"
                   :body="__('backoffice.drivers.confirm_reactivate')"
                   :confirm-label="__('backoffice.drivers.reactivate')" />
    @endif
</div>
