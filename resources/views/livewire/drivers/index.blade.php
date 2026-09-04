<div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.drivers.fleet_size')" :value="number_format($statusCounts[null])" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.drivers.active')" :value="number_format($statusCounts[\App\Enums\DriverStatus::Active->value])" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.drivers.suspended')" :value="number_format($statusCounts[\App\Enums\DriverStatus::Suspended->value])" tone="warn">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.drivers.dormant')" :value="number_format($statusCounts[\App\Enums\DriverStatus::Dormant->value])">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByStatus(null)" :active="$status === null" :count="$statusCounts[null]">
                {{ __('backoffice.drivers.all') }}
            </x-chip-filter>
            @foreach (\App\Enums\DriverStatus::cases() as $case)
                <x-chip-filter wire:key="status-{{ $case->value }}" wire:click="filterByStatus('{{ $case->value }}')" :active="$status === $case->value" :count="$statusCounts[$case->value]">
                    {{ $case->label() }}
                </x-chip-filter>
            @endforeach
        </div>

        <x-slot:end>
            <x-field :label="__('backoffice.drivers.search_placeholder')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.drivers.search_placeholder')" class="w-72" />
        </x-slot:end>
    </x-toolbar>

    <x-panel class="mt-4" flush>
        <x-table loading="filterByStatus,resetFilters,search,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.drivers.column_driver') }}</x-th>
                <x-th>{{ __('backoffice.drivers.column_identity') }}</x-th>
                <x-th>{{ __('backoffice.drivers.column_license') }}</x-th>
                <x-th>{{ __('backoffice.drivers.column_status') }}</x-th>
                <x-th align="right">{{ __('backoffice.drivers.column_balance') }}</x-th>
                <x-th>{{ __('backoffice.drivers.column_cnps') }}</x-th>
            </x-slot:head>

            @foreach ($drivers as $driver)
                {{-- Toute la ligne est cliquable, mais la navigation reste
                     portée par un vrai lien : celui du nom, doublé d'une
                     surface étirée en absolu sur la ligne. Un `onclick` sur le
                     `<tr>` la rendait inatteignable au clavier et
                     court-circuitait `wire:navigate`. --}}
                <tr wire:key="driver-{{ $driver->id }}" class="group relative transition-colors hover:bg-surface">
                    @php
                        // « Suzuki Dzire - Blanc · AA-567-HJ-01 » : les parties
                        // que Yango n'a pas envoyées disparaissent au lieu de
                        // laisser un séparateur orphelin.
                        $vehicleLine = $driver->vehicle === null
                            ? __('backoffice.drivers.no_vehicle')
                            : implode(' · ', array_filter([
                                $driver->vehicle->description(),
                                $driver->vehicle->plate_number,
                            ]));
                        $cnps = $cnpsStatuses[$driver->id] ?? null;
                    @endphp
                    <x-td>
                        <div class="flex items-center gap-2.5">
                            <x-avatar :initials="$driver->initials()" />
                            <span class="min-w-0">
                                {{-- `after:absolute after:inset-0` étire la zone
                                     cliquable du lien sur toute la ligne sans
                                     ajouter de second arrêt de tabulation. --}}
                                <a href="{{ route('bo.drivers.show', $driver) }}" wire:navigate
                                   class="block text-sm font-bold text-ink after:absolute after:inset-0 after:content-[''] group-hover:text-primary-text">
                                    {{ $driver->fullName() }}
                                </a>
                                <span class="block truncate text-xs text-muted">{{ $vehicleLine }}</span>
                            </span>
                        </div>
                    </x-td>
                    <x-td nowrap>
                        <span class="block font-mono text-sm text-ink">{{ $driver->yango_id ?? '—' }}</span>
                        <span class="block font-mono text-xs text-muted">{{ $driver->phone }}</span>
                    </x-td>
                    <x-td mono nowrap>{{ $driver->license_number ?? '—' }}</x-td>
                    <x-td>
                        <x-badge :classes="$driver->status->badgeClasses()">{{ $driver->status->label() }}</x-badge>
                    </x-td>
                    <x-td align="right" nowrap>
                        @if ($driver->yango_balance === null)
                            <span class="text-sm text-muted">—</span>
                        @else
                            <span class="text-sm font-bold tabular-nums text-ink">{{ number_format($driver->yango_balance, 0, ',', ' ') }} FCFA</span>
                        @endif
                    </x-td>
                    <x-td nowrap>
                        @if ($cnps === null)
                            <span class="text-sm text-muted">—</span>
                        @else
                            <x-badge :classes="$cnps->badgeClasses()">{{ $cnps->label() }}</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach

            @if ($drivers->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :title="__('backoffice.drivers.none_found')" :hint="__('backoffice.drivers.none_found_hint')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">
                                {{ __('backoffice.drivers.reset_filters') }}
                            </x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($drivers->hasPages())
                <x-slot:footer>{{ $drivers->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>
</div>
