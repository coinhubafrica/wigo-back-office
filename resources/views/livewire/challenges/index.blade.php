<div>
    <x-slot:actions>
        <x-button x-on:click="$dispatch('open-challenge-wizard')">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.challenges.new') }}
        </x-button>
    </x-slot:actions>

    @include('livewire.challenges.partials.tabs', ['active' => 'challenges'])

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($kpis as $kpi)
            <x-kpi-card :label="$kpi['label']" :value="$kpi['value']" :hint="$kpi['detail']" :tone="$kpi['tone']" />
        @endforeach
    </div>

    <x-panel class="mt-4" :title="__('backoffice.challenges.total')"
             :subtitle="trans_choice('backoffice.challenges.count', $challenges->total(), ['count' => $challenges->total()])" flush>
        <x-slot:actions>
            @foreach ($chips as $chip)
                <x-chip-filter wire:key="chip-{{ $chip['key'] }}" wire:click="setFilter('{{ $chip['key'] }}')" :active="$filter === $chip['key']" :count="$chip['count']">
                    {{ $chip['label'] }}
                </x-chip-filter>
            @endforeach
        </x-slot:actions>

        <x-table loading="setFilter,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.challenges.column_challenge') }}</x-th>
                <x-th>{{ __('backoffice.challenges.column_criteria') }}</x-th>
                <x-th>{{ __('backoffice.challenges.column_period') }}</x-th>
                <x-th>{{ __('backoffice.challenges.column_reward') }}</x-th>
                <x-th align="right">{{ __('backoffice.challenges.column_participants') }}</x-th>
                <x-th>{{ __('backoffice.challenges.column_status') }}</x-th>
                <x-th><span class="sr-only">{{ __('backoffice.challenges.column_challenge') }}</span></x-th>
            </x-slot:head>

            @foreach ($challenges as $challenge)
                <tr wire:key="challenge-{{ $challenge->id }}" class="group transition-colors hover:bg-surface">
                    <x-td>
                        <div class="flex items-center gap-3">
                            {{-- Code du type : carré et non pastille, pour se distinguer
                                 de la pastille d'état. Couleurs de l'énumération. --}}
                            <span class="shrink-0 rounded px-2 py-1 text-[10px] font-bold tracking-wide {{ $challenge->type->badgeClasses() }}">
                                {{ $challenge->type->code() }}
                            </span>
                            <span class="min-w-0">
                                <a href="{{ route('bo.challenges.show', $challenge) }}" wire:navigate
                                   class="text-[13.5px] font-bold text-ink group-hover:text-primary-text">
                                    {{ $challenge->name }}
                                </a>
                                <span class="block text-xs text-muted">
                                    <span class="font-mono">{{ $challenge->reference }}</span> · {{ $challenge->type->label() }}
                                </span>
                            </span>
                        </div>
                    </x-td>
                    <x-td class="max-w-[220px] text-xs leading-relaxed">{{ $challenge->criteriaSummary() }}</x-td>
                    <x-td nowrap class="text-xs">
                        <span class="block text-ink">{{ $challenge->period_start->translatedFormat('j M') }} → {{ $challenge->period_end->translatedFormat('j M') }}</span>
                        <span class="block text-muted">{{ $challenge->recurrence->shortLabel() }}</span>
                    </x-td>
                    <x-td class="text-xs">
                        <b class="block text-[13px] text-ink">{{ $challenge->prizeLabel() }}</b>
                        <span class="block text-muted">{{ $challenge->prizeSubLabel() }}</span>
                    </x-td>
                    <x-td align="right" nowrap class="text-[13.5px] font-semibold tabular-nums">
                        {{ $challenge->participants_count === null ? '—' : number_format($challenge->participants_count, 0, ',', ' ') }}
                    </x-td>
                    <x-td><x-badge :classes="$challenge->status->badgeClasses()">{{ $challenge->status->label() }}</x-badge></x-td>
                    <x-td align="right">
                        <a href="{{ route('bo.challenges.show', $challenge) }}" wire:navigate
                           aria-label="{{ __('backoffice.challenges.open_challenge', ['name' => $challenge->name]) }}"
                           class="inline-flex items-center px-1 text-primary transition-transform group-hover:translate-x-0.5">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </x-td>
                </tr>
            @endforeach

            @if ($challenges->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :title="__('backoffice.challenges.none_found')" :hint="__('backoffice.challenges.none_found_hint')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="setFilter('tous')" target="setFilter">{{ __('backoffice.challenges.reset_filters') }}</x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($challenges->hasPages())
                <x-slot:footer>{{ $challenges->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>

    {{-- Clé stable : sans elle, chaque re-rendu de la liste (filtre,
         pagination) réinitialiserait l'assistant et fermerait la modale. --}}
    <livewire:challenges.wizard wire:key="challenge-wizard" />
</div>
