<div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.fleet_size') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($statusCounts[null]) }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.active') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($statusCounts[\App\Enums\DriverStatus::Active->value]) }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.suspended') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($statusCounts[\App\Enums\DriverStatus::Suspended->value]) }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.dormant') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($statusCounts[\App\Enums\DriverStatus::Dormant->value]) }}</p>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-1.5">
            <button wire:click="filterByStatus(null)"
                    @class([
                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                        'border-primary bg-primary-tint text-primary-text' => $status === null,
                        'border-line bg-card text-muted hover:border-primary' => $status !== null,
                    ])>
                {{ __('backoffice.drivers.all') }} <span class="opacity-70">{{ $statusCounts[null] }}</span>
            </button>
            @foreach (\App\Enums\DriverStatus::cases() as $case)
                <button wire:click="filterByStatus('{{ $case->value }}')"
                        @class([
                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                            'border-primary bg-primary-tint text-primary-text' => $status === $case->value,
                            'border-line bg-card text-muted hover:border-primary' => $status !== $case->value,
                        ])>
                    {{ $case->label() }} <span class="opacity-70">{{ $statusCounts[$case->value] }}</span>
                </button>
            @endforeach
        </div>

        <span class="flex-1"></span>

        <input wire:model.live.debounce.400ms="search" type="search"
               placeholder="{{ __('backoffice.drivers.search_placeholder') }}"
               class="w-72 rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary focus:outline-none">
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-surface">
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.column_driver') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.column_status') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.drivers.column_phone') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($drivers as $driver)
                        <tr wire:key="driver-{{ $driver->id }}" class="cursor-pointer hover:bg-surface"
                            onclick="window.location='{{ route('bo.drivers.show', $driver) }}'">
                            <td class="border-b border-line px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded bg-primary-tint text-sm font-semibold text-primary-text">
                                        {{ $driver->initials() }}
                                    </span>
                                    <span>
                                        <b class="block text-sm text-ink">{{ $driver->fullName() }}</b>
                                        <span class="text-xs text-muted">{{ $driver->vehicle?->plate_number ?? __('backoffice.drivers.no_vehicle') }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="border-b border-line px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $driver->status->badgeClasses() }}">
                                    {{ $driver->status->label() }}
                                </span>
                            </td>
                            <td class="border-b border-line px-4 py-3 text-sm text-ink">{{ $driver->phone }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-10 text-center">
                                <p class="text-sm font-semibold text-ink">{{ __('backoffice.drivers.none_found') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('backoffice.drivers.none_found_hint') }}</p>
                                <button wire:click="resetFilters" class="mt-3 rounded border border-line bg-card px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface">
                                    {{ __('backoffice.drivers.reset_filters') }}
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $drivers->links() }}
    </div>
</div>
