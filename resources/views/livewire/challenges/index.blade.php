<div>
    @include('livewire.challenges.partials.tabs', ['active' => 'challenges'])

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($kpis as $kpi)
            <div class="rounded border border-line bg-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ $kpi['label'] }}</p>
                <p class="mt-1.5 text-3xl font-semibold {{ $kpi['tone'] }}">{{ $kpi['value'] }}</p>
                <p class="mt-1 text-xs text-muted">{{ $kpi['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <p class="text-[15px] font-bold text-ink">
                {{ __('backoffice.challenges.total') }}
                <span class="ml-1.5 text-sm font-normal text-muted">{{ trans_choice('backoffice.challenges.count', $challenges->total(), ['count' => $challenges->total()]) }}</span>
            </p>

            <button type="button" x-on:click="$dispatch('open-challenge-wizard')"
                    class="flex items-center gap-2 rounded bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover">
                <span class="text-base leading-none">+</span>
                {{ __('backoffice.challenges.new') }}
            </button>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-line px-5 py-3.5">
            @foreach ($chips as $chip)
                <button type="button" wire:click="setFilter('{{ $chip['key'] }}')"
                        @class([
                            'flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                            'border-primary bg-primary-tint text-primary-text' => $filter === $chip['key'],
                            'border-line bg-card text-ink hover:border-primary' => $filter !== $chip['key'],
                            'opacity-55' => $chip['count'] === 0 && $filter !== $chip['key'],
                        ])>
                    {{ $chip['label'] }}
                    <span @class([
                        'rounded-full px-1.5 py-0.5 text-[10.5px] font-bold',
                        'bg-primary text-white' => $filter === $chip['key'],
                        'bg-zinc-100 text-muted' => $filter !== $chip['key'],
                    ])>{{ $chip['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="setFilter">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-surface">
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_challenge') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_criteria') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_period') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_reward') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_participants') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_status') }}</th>
                        <th class="border-b border-line px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($challenges as $challenge)
                        <tr wire:key="challenge-{{ $challenge->id }}" class="group transition-colors hover:bg-surface">
                            <td class="border-b border-line px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="shrink-0 rounded px-2 py-1 text-[10px] font-bold tracking-wide {{ $challenge->type->badgeClasses() }}">
                                        {{ $challenge->type->code() }}
                                    </span>
                                    <span class="min-w-0">
                                        {{-- Le lien porte la navigation : la ligne entière est cliquable
                                             via `absolute inset-0`, tout en restant focusable au clavier. --}}
                                        <a href="{{ route('bo.challenges.show', $challenge) }}" wire:navigate
                                           class="text-[13.5px] font-bold text-ink group-hover:text-primary-text">
                                            {{ $challenge->name }}
                                        </a>
                                        <span class="block text-xs text-muted">
                                            <span class="font-mono">{{ $challenge->reference }}</span> · {{ $challenge->type->label() }}
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td class="max-w-[220px] border-b border-line px-4 py-3 text-xs leading-relaxed text-zinc-700">
                                {{ $challenge->criteriaSummary() }}
                            </td>
                            <td class="border-b border-line px-4 py-3 text-xs">
                                <span class="block text-ink">{{ $challenge->period_start->translatedFormat('j M') }} → {{ $challenge->period_end->translatedFormat('j M') }}</span>
                                <span class="block text-muted">{{ $challenge->recurrence->shortLabel() }}</span>
                            </td>
                            <td class="border-b border-line px-4 py-3 text-xs">
                                <b class="block text-[13px] text-ink">{{ $challenge->prizeLabel() }}</b>
                                <span class="block text-muted">{{ $challenge->prizeSubLabel() }}</span>
                            </td>
                            <td class="border-b border-line px-4 py-3 text-right text-[13.5px] font-semibold text-ink">
                                {{ $challenge->participants_count === null ? '—' : number_format($challenge->participants_count, 0, ',', ' ') }}
                            </td>
                            <td class="border-b border-line px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $challenge->status->badgeClasses() }}">
                                    {{ $challenge->status->label() }}
                                </span>
                            </td>
                            <td class="border-b border-line px-4 py-3 text-right">
                                <a href="{{ route('bo.challenges.show', $challenge) }}" wire:navigate
                                   aria-label="{{ __('backoffice.challenges.open_challenge', ['name' => $challenge->name]) }}"
                                   class="inline-block px-1 text-primary transition-transform group-hover:translate-x-0.5">→</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center">
                                <p class="text-sm font-semibold text-ink">{{ __('backoffice.challenges.none_found') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('backoffice.challenges.none_found_hint') }}</p>
                                <button wire:click="setFilter('tous')" class="mt-3 rounded border border-line bg-card px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface">
                                    {{ __('backoffice.challenges.reset_filters') }}
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $challenges->links() }}
    </div>

    {{-- Clé stable : sans elle, chaque re-rendu de la liste (filtre,
         pagination) réinitialiserait l'assistant et fermerait la modale. --}}
    <livewire:challenges.wizard wire:key="challenge-wizard" />
</div>
