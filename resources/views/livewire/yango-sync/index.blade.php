{{--
    Rattrapage manuel des journaux datés du parc.

    L'écran existe pour que le creux se voie : le tableau donne, journée par
    journée, ce que la base porte déjà. Un bouton seul obligerait l'agent à
    relancer à l'aveugle, et à recommencer sans savoir si cela avait servi.
--}}
{{--
    `wire:poll` seulement tant qu'une passe est en vol : un écran qui se
    rafraîchit en permanence rejouerait les trois requêtes de couverture toutes
    les cinq secondes pour ne rien apprendre.
--}}
<div class="flex flex-col gap-4" @if ($hasPendingRuns) wire:poll.5s @endif>
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
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="rebuildActivity" class="size-4 rounded border-line text-primary">
                        {{ __('backoffice.yango_sync.rebuild_activity') }}
                    </label>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('backoffice.yango_sync.rebuild_activity_hint') }}</p>
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

    @if ($runs->isNotEmpty())
        <x-panel :title="__('backoffice.yango_sync.runs_title')" :subtitle="__('backoffice.yango_sync.runs_hint')" flush>
            <x-table>
                <x-slot:head>
                    <x-th>{{ __('backoffice.yango_sync.column_day') }}</x-th>
                    <x-th>{{ __('backoffice.yango_sync.column_kind') }}</x-th>
                    <x-th>{{ __('backoffice.yango_sync.column_status') }}</x-th>
                    <x-th>{{ __('backoffice.yango_sync.column_launched_by') }}</x-th>
                    <x-th align="right">{{ __('backoffice.yango_sync.column_result') }}</x-th>
                </x-slot:head>

                @foreach ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}" class="transition-colors hover:bg-surface">
                        <x-td nowrap mono>{{ $run->day->format('Y-m-d') }}</x-td>
                        <x-td nowrap>{{ $run->kind->label() }}</x-td>
                        <x-td nowrap>
                            <x-badge :classes="$run->status->badgeClasses()" :pulse="$run->status->isPending()">
                                {{ $run->status->label() }}
                            </x-badge>
                        </x-td>
                        <x-td nowrap :muted="$run->user === null">
                            {{ $run->user?->name ?? __('backoffice.yango_sync.launched_by_schedule') }}
                        </x-td>
                        <x-td align="right" class="text-xs">
                            @if ($run->hasFailed())
                                <span class="text-err-text">{{ $run->error }}</span>
                            @elseif ($run->summary)
                                <span class="text-muted">
                                    @foreach ($run->summary as $label => $value)
                                        {{ $label }}&nbsp;{{ number_format((int) $value, 0, ',', ' ') }}@if (! $loop->last), @endif
                                    @endforeach
                                </span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </x-td>
                    </tr>
                @endforeach
            </x-table>
        </x-panel>
    @endif

    <x-panel :title="__('backoffice.yango_sync.coverage_title')" :subtitle="__('backoffice.yango_sync.coverage_hint')" flush>
        <x-table loading="queue,from,to">
            <x-slot:head>
                <x-th>{{ __('backoffice.yango_sync.column_day') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_completed') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_cancelled') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_activity') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.transactions') }}</x-th>
            </x-slot:head>

            @foreach ($coverage as $row)
                <tr wire:key="day-{{ $row['day'] }}" class="transition-colors hover:bg-surface">
                    <x-td nowrap mono>{{ $row['day'] }}</x-td>
                    <x-td align="right" mono :muted="$row['completed'] === 0">{{ number_format($row['completed'], 0, ',', ' ') }}</x-td>
                    <x-td align="right" mono :muted="$row['cancelled'] === 0">{{ number_format($row['cancelled'], 0, ',', ' ') }}</x-td>
                    {{-- Le cumul journalier découle des courses terminées : un écart se signale, c'est lui que le recompte répare. --}}
                    <x-td align="right" mono :muted="$row['activity'] === 0">
                        <span @class(['font-semibold text-err-text' => $row['drifted']])>
                            {{ number_format($row['activity'], 0, ',', ' ') }}
                        </span>
                        @if ($row['drifted'])
                            <span class="ml-1 text-xs font-normal text-err-text" title="{{ __('backoffice.yango_sync.drift_hint') }}">&#9888;</span>
                        @endif
                    </x-td>
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
