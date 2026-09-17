{{--
    Rattrapage manuel des courses du parc.

    Un seul tableau : chaque ligne est une journée, avec ce que le parc a fait,
    ce que le tableau de bord en a retenu, où en est la dernière passe, et de
    quoi recompter. Les deux tableaux d'avant obligeaient l'agent à lire un
    écart d'un côté et à chercher le bouton de l'autre.

    `wire:poll` seulement tant qu'une passe est en vol : un écran qui se
    rafraîchit en permanence rejouerait ses requêtes pour ne rien apprendre.
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

    <x-panel :title="__('backoffice.yango_sync.history_title')" :subtitle="__('backoffice.yango_sync.history_hint')" :count="$rows->total()" flush>
        <x-slot:actions>
            <x-toolbar>
                <x-field :label="__('backoffice.yango_sync.history_from')" name="historyFrom" type="date" wire:model.live="historyFrom" class="w-40" />
                <x-field :label="__('backoffice.yango_sync.history_to')" name="historyTo" type="date" wire:model.live="historyTo" class="w-40" />
            </x-toolbar>
        </x-slot:actions>

        <x-table loading="historyFrom,historyTo,recount,resetHistoryFilter,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.yango_sync.column_day') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_completed') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_cancelled') }}</x-th>
                <x-th align="right">{{ __('backoffice.yango_sync.column_dashboard') }}</x-th>
                <x-th>{{ __('backoffice.yango_sync.column_status') }}</x-th>
                <x-th align="right"><span class="sr-only">{{ __('backoffice.yango_sync.column_action') }}</span></x-th>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="day-{{ $row['day'] }}" class="transition-colors hover:bg-surface">
                    <x-td nowrap mono>{{ $row['day'] }}</x-td>

                    {{-- Une journée jamais comptée affiche un tiret, pas un zéro : l'absence de compte n'est pas une absence de course. --}}
                    <x-td align="right" mono :muted="$row['completed'] === null">
                        {{ $row['completed'] === null ? '—' : number_format($row['completed'], 0, ',', ' ') }}
                    </x-td>

                    <x-td align="right" mono :muted="! $row['cancelled']">
                        {{ $row['cancelled'] === null ? '—' : number_format($row['cancelled'], 0, ',', ' ') }}
                    </x-td>

                    {{-- Le cumul journalier découle des courses terminées : un écart se signale, c'est lui que le recompte répare. --}}
                    <x-td align="right" mono :muted="$row['dashboard'] === null">
                        <span @class(['font-semibold text-err-text' => $row['drifted']])>
                            {{ $row['dashboard'] === null ? '—' : number_format($row['dashboard'], 0, ',', ' ') }}
                        </span>
                        @if ($row['drifted'])
                            <span class="ml-1 text-xs font-normal text-err-text" title="{{ __('backoffice.yango_sync.drift_hint') }}">&#9888;</span>
                        @endif
                    </x-td>

                    <x-td nowrap>
                        @if ($row['run'])
                            <x-badge :classes="$row['run']->status->badgeClasses()" :pulse="$row['run']->status->isPending()">
                                {{ $row['run']->status->label() }}
                            </x-badge>
                            @if ($row['run']->hasFailed() && $row['run']->error)
                                <p class="mt-1 max-w-xs truncate text-xs text-err-text" title="{{ $row['run']->error }}">{{ $row['run']->error }}</p>
                            @endif
                        @else
                            <span class="text-xs text-muted">—</span>
                        @endif
                    </x-td>

                    <x-td align="right" nowrap>
                        @if ($canQueue)
                            {{-- Sorti de la directive : `@disabled` ne sait pas analyser une chaîne `?->` et la découperait en attributs. --}}
                            @php($recountRunning = $row['activityRun']?->status->isPending() ?? false)
                            <x-button
                                variant="secondary"
                                size="sm"
                                wire:click="recount('{{ $row['day'] }}')"
                                target="recount"
                                :disabled="$recountRunning"
                            >
                                {{ __('backoffice.yango_sync.recount') }}
                            </x-button>
                        @endif
                    </x-td>
                </tr>
            @endforeach

            @if ($rows->isEmpty())
                <x-slot:empty>
                    <x-empty-state :title="__('backoffice.yango_sync.empty_title')" :hint="__('backoffice.yango_sync.empty_body')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetHistoryFilter" target="resetHistoryFilter">
                                {{ __('backoffice.yango_sync.reset_history') }}
                            </x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($rows->hasPages())
                <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>
</div>
