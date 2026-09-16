{{--
    Rattrapage manuel des journaux datés du parc.

    L'écran existe pour que le creux se voie : le tableau donne, journée par
    journée, ce que la base porte déjà. Un bouton seul obligerait l'agent à
    relancer à l'aveugle, et à recommencer sans savoir si cela avait servi.
--}}
<div class="flex flex-col gap-4">
    <x-panel :title="__('backoffice.yango_sync.period_title')" :subtitle="__('backoffice.yango_sync.period_hint')">
        <form wire:submit="queue" class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('backoffice.yango_sync.from')" name="from" type="date" wire:model.live="from" required />
            <x-field :label="__('backoffice.yango_sync.to')" name="to" type="date" wire:model.live="to" required />

            <fieldset class="sm:col-span-2">
                <legend class="text-sm font-medium text-ink">{{ __('backoffice.yango_sync.what') }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="syncOrders" class="size-4 rounded border-line text-primary">
                        {{ __('backoffice.yango_sync.orders') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="syncTransactions" class="size-4 rounded border-line text-primary">
                        {{ __('backoffice.yango_sync.transactions') }}
                    </label>
                </div>
                @error('syncOrders')
                    <p class="mt-1.5 text-xs text-err-text">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2">
                <p class="text-xs text-muted">
                    {{ trans_choice('backoffice.yango_sync.day_count', $dayCount, ['count' => $dayCount]) }}
                </p>
                @if ($canQueue)
                    <x-button type="submit" target="queue">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
                        {{ __('backoffice.yango_sync.queue') }}
                        <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                    </x-button>
                @endif
            </div>
        </form>
    </x-panel>

    <x-panel :title="__('backoffice.yango_sync.coverage_title')" :subtitle="__('backoffice.yango_sync.coverage_hint')" flush>
        <x-table loading="queue,from,to">
            <x-slot:head>
                <x-th>{{ __('backoffice.yango_sync.column_day') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.orders') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.transactions') }}</x-th>
            </x-slot:head>

            @foreach ($coverage as $row)
                <tr wire:key="day-{{ $row['day'] }}" class="transition-colors hover:bg-surface">
                    <x-td nowrap mono>{{ $row['day'] }}</x-td>
                    <x-td align="right" mono :muted="$row['orders'] === 0">{{ number_format($row['orders'], 0, ',', ' ') }}</x-td>
                    <x-td align="right" mono :muted="$row['transactions'] === 0">{{ number_format($row['transactions'], 0, ',', ' ') }}</x-td>
                </tr>
            @endforeach

            @if ($coverage === [])
                <x-slot:empty>
                    <x-empty-state :title="__('backoffice.yango_sync.empty_title')">
                        {{ __('backoffice.yango_sync.empty_body') }}
                    </x-empty-state>
                </x-slot:empty>
            @endif
        </x-table>
    </x-panel>
</div>
