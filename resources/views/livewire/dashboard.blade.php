{{--
    Tableau de bord.

    Trois étages : la semaine observée, les indicateurs avec la courbe
    d'évolution à leur droite, puis le détail — l'activité et la file du
    support à gauche, ce qui réclame un geste à droite.

    Chaque carte est un lien vers le module qui porte le détail : l'écran
    constate, il ne remplace aucun module. Les blocs absents le sont parce que
    l'agent n'a pas le droit d'accès correspondant, pas parce qu'ils sont vides.
--}}
<div>
    @if ($cards === [] && $alerts === [] && $nextDraw === null)
        <x-empty-state tone="neutral" size="lg"
                       :title="__('backoffice.dashboard.no_cards')"
                       :hint="__('backoffice.dashboard.no_cards_hint')" />
    @else
        {{-- ------------------------------------------------------------ --}}
        {{-- La semaine observée. Les cartes de courses la suivent ; les   --}}
        {{-- autres restent au temps réel, et la note le dit.              --}}
        {{-- ------------------------------------------------------------ --}}
        <x-toolbar class="mb-5">
            <span class="text-[13px] font-semibold text-muted">{{ __('backoffice.dashboard.period_weekly') }}</span>

            <x-field type="select" name="week" wire:model.live="week" label-hidden
                     :label="__('backoffice.dashboard.period_weekly')"
                     class="min-w-[18rem]">
                @foreach ($weekOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-field>

            <x-slot:end>
                <p class="text-[12.5px] text-muted">
                    {{ $weekInProgress
                        ? __('backoffice.dashboard.week_in_progress_notice')
                        : __('backoffice.dashboard.week_closed_notice') }}
                </p>
            </x-slot:end>
        </x-toolbar>

        {{-- ------------------------------------------------------------ --}}
        {{-- Indicateurs, la courbe occupant les deux colonnes de droite.  --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ($cards as $card)
                <x-kpi-card :label="$card['label']"
                            :value="$card['value']"
                            :hint="$card['hint']"
                            :alert="$card['alert']"
                            :tone="$card['tone']"
                            :href="route($card['route'])">
                    <x-slot:icon>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $card['icon'] }}"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
            @endforeach

            @if ($weeklyTrend !== [])
                <x-panel :title="__('backoffice.dashboard.trend_12_weeks')"
                         class="sm:col-span-2 xl:col-span-2 xl:col-start-4 xl:row-span-2 xl:row-start-1 xl:flex xl:flex-col">
                    <x-slot:actions>
                        <span class="text-[11.5px] text-muted">{{ __('backoffice.dashboard.trend_last_point') }}</span>
                    </x-slot:actions>

                    <x-trend-chart :points="$weeklyTrend" :label="__('backoffice.dashboard.trend_12_weeks')" />
                </x-panel>
            @endif
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- Le détail : l'activité et la file à gauche, les gestes à      --}}
        {{-- faire à droite.                                               --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="mt-4 grid items-start gap-4 xl:grid-cols-[1.6fr_1fr]">
            <div class="flex flex-col gap-4">
                @if ($dailyOrders !== [])
                    <x-panel :title="__('backoffice.dashboard.orders_per_day', ['period' => $weekLabel])"
                             :subtitle="$weekInProgress ? __('backoffice.dashboard.week_in_progress') : null">
                        <x-bar-chart :bars="$dailyOrders" />
                    </x-panel>
                @endif

                @can(\App\Enums\BackOfficeModule::SupportRequests->permission())
                    <x-panel :title="__('backoffice.dashboard.latest_requests')" flush>
                        <x-slot:actions>
                            <a href="{{ route(\App\Enums\BackOfficeModule::SupportRequests->route()) }}" wire:navigate
                               class="text-[13px] font-semibold text-primary-text hover:underline">
                                {{ __('backoffice.dashboard.open_queue') }}
                            </a>
                        </x-slot:actions>

                        <x-table>
                            <x-slot:head>
                                <x-th>{{ __('backoffice.dashboard.column_reference') }}</x-th>
                                <x-th>{{ __('backoffice.dashboard.column_driver') }}</x-th>
                                <x-th>{{ __('backoffice.dashboard.column_subject') }}</x-th>
                                <x-th>{{ __('backoffice.dashboard.column_status') }}</x-th>
                                <x-th align="right">{{ __('backoffice.dashboard.column_age') }}</x-th>
                            </x-slot:head>

                            @foreach ($latestRequests as $request)
                                <tr wire:key="request-{{ $request->id }}" class="transition-colors hover:bg-surface">
                                    <x-td mono>#{{ $request->number }}</x-td>
                                    <x-td>
                                        <div class="flex items-center gap-2.5">
                                            <x-avatar :initials="$request->driver?->initials() ?? '—'" alt="" size="sm" />
                                            <span class="min-w-0">
                                                <span class="block truncate text-[13.5px] font-semibold text-ink">{{ $request->driver?->fullName() ?? __('backoffice.dashboard.unknown_driver') }}</span>
                                                <span class="block truncate text-xs text-muted">{{ $request->category->label() }}</span>
                                            </span>
                                        </div>
                                    </x-td>
                                    <x-td>{{ $request->subject ?? $request->category->label() }}</x-td>
                                    <x-td>
                                        <x-badge :classes="$request->status->badgeClasses()">{{ $request->status->label() }}</x-badge>
                                    </x-td>
                                    <x-td align="right" muted nowrap>{{ $request->created_at->diffForHumans(short: true) }}</x-td>
                                </tr>
                            @endforeach

                            @if ($latestRequests->isEmpty())
                                <x-slot:empty>
                                    <x-empty-state tone="ok" :title="__('backoffice.dashboard.no_open_requests')" />
                                </x-slot:empty>
                            @endif
                        </x-table>
                    </x-panel>
                @endcan
            </div>

            <div class="flex flex-col gap-4">
                <x-panel :title="__('backoffice.dashboard.alerts')" :count="$alerts === [] ? null : count($alerts)" flush>
                    @if ($alerts === [])
                        <x-empty-state tone="ok" :title="__('backoffice.dashboard.no_alerts')" />
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($alerts as $alert)
                                @php
                                    // Classe complète résolue ici : Tailwind ne lit pas les fragments.
                                    $dot = match ($alert['tone']) {
                                        'err' => 'bg-err-text',
                                        'warn' => 'bg-warn-text',
                                        default => 'bg-neutral-text',
                                    };
                                @endphp
                                <li class="flex items-start gap-3 px-5 py-3">
                                    <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                                    <span class="flex-1 text-[13.5px] leading-snug text-ink">{{ $alert['text'] }}</span>
                                    <a href="{{ route($alert['route']) }}" wire:navigate
                                       class="shrink-0 text-[13px] font-semibold text-primary-text hover:underline">
                                        {{ __('backoffice.dashboard.alert_open') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-panel>

                @can(\App\Enums\BackOfficeModule::Challenges->permission())
                    <x-panel :title="__('backoffice.dashboard.next_draw')">
                        @if ($nextDraw === null)
                            <p class="text-[13.5px] text-muted">{{ __('backoffice.dashboard.no_draw') }}</p>
                        @else
                            <p class="text-sm font-semibold text-ink">{{ $nextDraw->name }}</p>
                            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                                {{ __('backoffice.dashboard.draw_period', [
                                    'from' => $nextDraw->period_start->translatedFormat('j M'),
                                    'to' => $nextDraw->period_end->translatedFormat('j M Y'),
                                ]) }}
                            </p>
                            <div class="mt-3">
                                <a href="{{ route('bo.challenges.show', $nextDraw) }}" wire:navigate
                                   class="text-[13px] font-semibold text-primary-text hover:underline">
                                    {{ __('backoffice.dashboard.open_challenge') }}
                                </a>
                            </div>
                        @endif
                    </x-panel>
                @endcan
            </div>
        </div>
    @endif
</div>
