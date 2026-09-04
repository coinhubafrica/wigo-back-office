<div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.vehicles.fleet_size')" :value="number_format($counts[null])" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M5 17a2 2 0 1 0 0 .01M19 17a2 2 0 1 0 0 .01M3 13l2-5a2 2 0 0 1 1.9-1.4h10.2A2 2 0 0 1 19 8l2 5v4H3v-4Z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.vehicles.assigned')" :value="number_format($counts[\App\Livewire\Vehicles\Index::FILTER_ASSIGNED])" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.vehicles.unassigned')" :value="number_format($counts[\App\Livewire\Vehicles\Index::FILTER_UNASSIGNED])" tone="warn">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.vehicles.inactive')" :value="number_format($counts[\App\Livewire\Vehicles\Index::FILTER_INACTIVE])">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64 5.64 18.36"/><circle cx="12" cy="12" r="9"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterBy(null)" :active="$filter === null" :count="$counts[null]">
                {{ __('backoffice.vehicles.all') }}
            </x-chip-filter>
            @foreach ([
                \App\Livewire\Vehicles\Index::FILTER_ASSIGNED => __('backoffice.vehicles.assigned'),
                \App\Livewire\Vehicles\Index::FILTER_UNASSIGNED => __('backoffice.vehicles.unassigned'),
                \App\Livewire\Vehicles\Index::FILTER_INACTIVE => __('backoffice.vehicles.inactive'),
            ] as $key => $label)
                <x-chip-filter wire:key="filter-{{ $key }}" wire:click="filterBy('{{ $key }}')" :active="$filter === $key" :count="$counts[$key]">
                    {{ $label }}
                </x-chip-filter>
            @endforeach
        </div>

        <x-slot:end>
            <x-field :label="__('backoffice.vehicles.search_placeholder')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.vehicles.search_placeholder')" class="w-72" />
        </x-slot:end>
    </x-toolbar>

    <x-panel class="mt-4" flush>
        <x-table loading="filterBy,resetFilters,search,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.vehicles.column_vehicle') }}</x-th>
                <x-th>{{ __('backoffice.vehicles.column_plate') }}</x-th>
                <x-th>{{ __('backoffice.vehicles.column_driver') }}</x-th>
                <x-th>{{ __('backoffice.vehicles.column_yango_id') }}</x-th>
                <x-th>{{ __('backoffice.vehicles.column_sync') }}</x-th>
            </x-slot:head>

            @foreach ($vehicles as $vehicle)
                {{-- Même construction que la liste des conducteurs : la ligne
                     entière est cliquable, mais c'est le lien de la plaque qui
                     porte la navigation. --}}
                <tr wire:key="vehicle-{{ $vehicle->id }}" class="group relative transition-colors hover:bg-surface">
                    <x-td>
                        <a href="{{ route('bo.vehicles.show', $vehicle) }}" wire:navigate
                           class="block text-sm font-bold text-ink after:absolute after:inset-0 after:content-[''] group-hover:text-primary-text">
                            {{ $vehicle->description() ?: __('backoffice.vehicles.unknown_model') }}
                        </a>
                        @unless ($vehicle->is_active)
                            <span class="mt-0.5 block text-xs text-muted">{{ __('backoffice.vehicles.status_inactive') }}</span>
                        @endunless
                    </x-td>
                    <x-td mono nowrap>{{ $vehicle->plate_number }}</x-td>
                    <x-td>
                        @if ($vehicle->driver === null)
                            <span class="text-sm text-muted">{{ __('backoffice.vehicles.no_driver') }}</span>
                        @else
                            <span class="block text-sm text-ink">{{ $vehicle->driver->fullName() }}</span>
                            <span class="block font-mono text-xs text-muted">{{ $vehicle->driver->phone }}</span>
                        @endif
                    </x-td>
                    <x-td mono nowrap>{{ $vehicle->yango_id ?? '—' }}</x-td>
                    <x-td nowrap>
                        @if ($vehicle->last_sync_at === null)
                            <span class="text-xs text-muted">{{ __('backoffice.vehicles.never_synced') }}</span>
                        @else
                            <span class="text-xs text-muted">{{ $vehicle->last_sync_at->diffForHumans() }}</span>
                        @endif
                    </x-td>
                </tr>
            @endforeach

            @if ($vehicles->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :title="__('backoffice.vehicles.none_found')" :hint="__('backoffice.vehicles.none_found_hint')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">
                                {{ __('backoffice.vehicles.reset_filters') }}
                            </x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($vehicles->hasPages())
                <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>
</div>
