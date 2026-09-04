{{--
    Fiche véhicule : identité, affectation, synchronisation.

    Pas d'onglets ni de cartes d'indicateurs — rien dans le schéma ne pointe
    vers un véhicule. Mieux vaut une fiche courte et vraie qu'une grille de
    tirets (voir ce qu'a coûté « courses de la semaine » sur la fiche
    conducteur).
--}}
<div class="flex flex-col gap-4">
    <x-slot:back>
        <x-back-link :href="route(\App\Enums\BackOfficeModule::Vehicles->route())">{{ __('backoffice.vehicles.back_to_list') }}</x-back-link>
    </x-slot:back>

    <x-panel>
        <div class="flex flex-wrap items-start gap-3.5">
            <span class="flex size-12 shrink-0 items-center justify-center rounded bg-surface text-muted" aria-hidden="true">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5h14l2 5v3a2 2 0 0 1-2 2M7 17v2M17 17v2"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-ink">
                    <span class="truncate">{{ $vehicle->description() ?: __('backoffice.vehicles.unknown_model') }}</span>
                    <x-badge :classes="$vehicle->is_active ? 'bg-ok-bg text-ok-text' : 'bg-neutral-bg text-neutral-text'">
                        {{ $vehicle->is_active ? __('backoffice.vehicles.status_active') : __('backoffice.vehicles.status_inactive') }}
                    </x-badge>
                </h2>
                <p class="mt-0.5 text-sm text-muted">
                    <span class="font-mono">{{ $vehicle->plate_number }}</span>
                    <span aria-hidden="true"> · </span>
                    {{ __('backoffice.vehicles.column_yango_id') }}
                    <span class="font-mono">{{ $vehicle->yango_id ?? '—' }}</span>
                </p>
                <p class="mt-1 text-xs text-muted">
                    @if ($vehicle->last_sync_at === null)
                        {{ __('backoffice.vehicles.never_synced') }}
                    @else
                        {{ __('backoffice.vehicles.synced_at', ['when' => $vehicle->last_sync_at->diffForHumans()]) }}
                    @endif
                </p>
            </div>
        </div>
    </x-panel>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-panel :title="__('backoffice.vehicles.identity_title')" :subtitle="__('backoffice.vehicles.identity_hint')">
            <x-dl>
                <x-dl-item :term="__('backoffice.vehicles.brand')">{{ $vehicle->brand ?? '—' }}</x-dl-item>
                <x-dl-item :term="__('backoffice.vehicles.model')">{{ $vehicle->model ?? '—' }}</x-dl-item>
                <x-dl-item :term="__('backoffice.vehicles.color')">{{ $vehicle->color ?? '—' }}</x-dl-item>
                <x-dl-item :term="__('backoffice.vehicles.column_plate')" mono>{{ $vehicle->plate_number }}</x-dl-item>
                <x-dl-item :term="__('backoffice.vehicles.catalogue_model')" class="sm:col-span-2">
                    @if ($vehicle->vehicleModel === null)
                        <span class="text-muted">{{ __('backoffice.vehicles.catalogue_model_missing') }}</span>
                    @else
                        {{ $vehicle->vehicleModel->vehicleBrand->name }} {{ $vehicle->vehicleModel->name }}
                    @endif
                    <span class="mt-0.5 block text-xs text-muted">{{ __('backoffice.vehicles.catalogue_model_hint') }}</span>
                </x-dl-item>
            </x-dl>
        </x-panel>

        <x-panel :title="__('backoffice.vehicles.assignment_title')" :subtitle="__('backoffice.vehicles.assignment_hint')">
            @if ($vehicle->driver === null)
                <x-empty-state tone="neutral" :title="__('backoffice.vehicles.no_driver')" />
            @else
                <a href="{{ route('bo.drivers.show', $vehicle->driver) }}" wire:navigate
                   class="group flex items-center gap-3 rounded border border-line p-3 transition-colors hover:bg-surface">
                    <x-avatar :initials="$vehicle->driver->initials()" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold text-ink group-hover:text-primary-text">{{ $vehicle->driver->fullName() }}</span>
                        <span class="block font-mono text-xs text-muted">{{ $vehicle->driver->phone }}</span>
                    </span>
                    <x-badge :classes="$vehicle->driver->status->badgeClasses()">{{ $vehicle->driver->status->label() }}</x-badge>
                </a>
            @endif
        </x-panel>
    </div>
</div>
