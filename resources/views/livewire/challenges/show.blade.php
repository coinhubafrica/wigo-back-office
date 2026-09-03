@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeStatus;
    use App\Enums\ChallengeType;
    use App\Enums\PrizeNature;

    $isRaffleOrSurprise = $challenge->type !== ChallengeType::Leaderboard;
    $drawDone = $challenge->drawn_at !== null;

    // Les directives Blade ne sont pas compilées dans l'attribut d'un composant :
    // `@js()` y resterait littéral. La charge utile est donc préparée ici.
    $duplicatePayload = \Illuminate\Support\Js::from(['template' => $this->duplicateTemplateKey()]);
@endphp
<div class="flex flex-col gap-4">
    <x-slot:back>
        <x-back-link :href="route(\App\Enums\BackOfficeModule::Challenges->route())">{{ __('backoffice.challenges.all_challenges') }}</x-back-link>
    </x-slot:back>

    {{-- En-tête : identité, résumé, actions dépendantes du statut --}}
    <x-panel flush>
        <div class="flex flex-wrap items-start justify-between gap-5 p-5">
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2.5 text-[11px] font-semibold uppercase tracking-wide">
                    <span class="text-primary-text">{{ $challenge->type->label() }}</span>
                    <span class="text-muted">·</span>
                    <span class="font-mono font-medium normal-case tracking-normal text-muted">{{ $challenge->reference }}</span>
                    <x-badge :classes="$challenge->status->badgeClasses()" class="normal-case tracking-normal">{{ $challenge->status->label() }}</x-badge>
                </p>

                <h2 class="mt-2 text-[26px] font-bold leading-tight tracking-tight text-ink">{{ $challenge->name }}</h2>

                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-muted">
                    {{ $challenge->criteriaSummary() }} — {{ $challenge->prizeLabel() }}
                    @if ($challenge->award_mode === AwardMode::SingleWinner)
                        {{ __('backoffice.challenges.for_single_winner') }}
                    @else
                        {{ trans_choice('backoffice.challenges.for_winners', $challenge->effectiveWinnersCount(), ['count' => $challenge->effectiveWinnersCount()]) }}
                    @endif
                </p>
            </div>

            {{-- Le vert reste aux états : approuver et verser sont des « allez-y »,
                 donc primaires ; rejeter ouvre un parcours destructeur. --}}
            <div class="flex w-full max-w-[290px] shrink-0 flex-col gap-2.5">
                @if ($challenge->status === ChallengeStatus::PendingApproval)
                    @can('approveSurpriseChallenge')
                        <x-button wire:click="approve" target="approve">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ __('backoffice.challenges.approve_challenge') }}
                            <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                        </x-button>
                        <x-button variant="danger-outline" wire:click="$toggle('showRejectForm')">{{ __('backoffice.challenges.reject') }}</x-button>
                    @endcan
                @elseif ($challenge->status === ChallengeStatus::Active && $canManage)
                    <x-button variant="secondary" wire:click="confirmAction('close_period')" target="confirmAction">{{ __('backoffice.challenges.close_period_now') }}</x-button>
                @elseif ($challenge->status === ChallengeStatus::PayoutPending && $canManage && $creditedCount < $totalWinners)
                    <x-button wire:click="confirmAction('credit_all')" target="confirmAction">{{ __('backoffice.challenges.deposit_all_on_yango') }}</x-button>
                @endif

                <x-button variant="secondary" x-on:click="$dispatch('open-challenge-wizard', {{ $duplicatePayload }})">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    {{ __('backoffice.challenges.duplicate_next_period') }}
                </x-button>
            </div>
        </div>

        @if ($showRejectForm)
            <form wire:submit="reject" class="border-t border-line bg-err-bg/40 p-5">
                <x-field :label="__('backoffice.challenges.rejection_reason')" name="rejectionReason" id="rejection"
                         wire:model="rejectionReason" required autofocus class="max-w-xl"
                         :placeholder="__('backoffice.challenges.rejection_reason_placeholder')" />
                <div class="mt-3 flex gap-2">
                    <x-button type="submit" variant="danger" target="reject">
                        {{ __('backoffice.challenges.confirm_reject') }}
                        <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                    </x-button>
                    <x-button type="button" variant="secondary" wire:click="$toggle('showRejectForm')">{{ __('backoffice.challenges.cancel') }}</x-button>
                </div>
            </form>
        @endif

        {{-- Définition en quatre cases --}}
        <div class="grid border-t border-line sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($definition as $cell)
                <div class="border-b border-line p-4 lg:border-b-0 lg:[&:not(:last-child)]:border-r">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $cell['label'] }}</p>
                    <p class="mt-1.5 text-[15px] font-semibold text-ink">{{ $cell['value'] }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-muted">{{ $cell['caption'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Chevron pivotant en SVG plutôt que ▲/▼, et `aria-expanded` : l'état
             déplié n'était porté que par le glyphe. --}}
        <button type="button" wire:click="$toggle('definitionOpen')"
                aria-expanded="{{ $definitionOpen ? 'true' : 'false' }}"
                class="flex w-full items-center gap-2 border-t border-line bg-surface px-5 py-3 text-left text-[13px] font-semibold text-ink transition-colors hover:bg-line/60">
            {{ $definitionOpen ? __('backoffice.challenges.hide_definition') : __('backoffice.challenges.show_definition') }}
            <svg @class(['size-3.5 text-muted transition-transform', 'rotate-180' => $definitionOpen])
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </button>

        @if ($definitionOpen)
            <div class="grid gap-6 border-t border-line bg-surface p-5 sm:grid-cols-2">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_criteria') }}</p>
                    <dl class="mt-2 space-y-2 text-[13px]">
                        @forelse ($challenge->activeCriteria() as $criterion)
                            <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                                <dt class="text-muted">{{ $criterion['label'] }}</dt>
                                <dd class="shrink-0 font-semibold text-ink">{{ $criterion['value'] }}</dd>
                            </div>
                        @empty
                            <p class="text-muted">{{ __('backoffice.challenges.no_criteria_defined') }}</p>
                        @endforelse
                    </dl>
                </div>

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.prize') }}</p>
                    <dl class="mt-2 space-y-2 text-[13px]">
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.prize_nature') }}</dt>
                            <dd class="font-semibold text-ink">
                                {{ $challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.physical_prize') : __('backoffice.challenges.cash_transfer') }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.value') }}</dt>
                            <dd class="font-semibold text-ink">{{ $challenge->prizeLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.award') }}</dt>
                            <dd class="font-semibold text-ink">{{ $challenge->prizeSubLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted">{{ __('backoffice.challenges.created_by') }}</dt>
                            <dd class="font-semibold text-ink">{{ $challenge->createdBy->name ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @endif
    </x-panel>

    {{-- Tirage : panneau mis en avant quand il reste à exécuter --}}
    @if ($isRaffleOrSurprise)
        <x-panel @class(['border-primary' => $challenge->status === ChallengeStatus::DrawPending])
                 :title="$drawDone ? __('backoffice.challenges.draw_result') : ($challenge->status === ChallengeStatus::DrawPending ? __('backoffice.challenges.draw_to_run') : __('backoffice.challenges.draw_awaiting_close'))">
            @if ($drawDone)
                <div class="flex flex-wrap items-center gap-5">
                    @if ($challenge->prize?->photo_url)
                        <img src="{{ $challenge->prize->photo_url }}" alt="{{ $challenge->prize->name }}"
                             class="size-[104px] shrink-0 rounded border border-line object-cover">
                    @endif
                    <div class="min-w-0">
                        @foreach ($challenge->winners as $winner)
                            <div wire:key="draw-winner-{{ $winner->id }}" @class(['mt-4 border-t border-line pt-4' => ! $loop->first])>
                                @if ($winner->winning_range_number)
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">
                                        {{ __('backoffice.challenges.winning_ticket') }} · <span class="font-mono">n° {{ number_format($winner->winning_range_number, 0, ',', ' ') }}</span>
                                    </p>
                                @endif
                                <p class="mt-1 text-xl font-bold text-ink">{{ $winner->driver->fullName() }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-ok-text">{{ $challenge->prizeLabel() }}</p>
                                <x-badge :tone="$winner->credited ? 'ok' : 'warn'" class="mt-2">
                                    {{ $winner->credited
                                        ? ($challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.prize_handed_over') : __('backoffice.challenges.credited'))
                                        : ($challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.prize_to_hand_over') : __('backoffice.challenges.bonus_to_deposit')) }}
                                </x-badge>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($challenge->status === ChallengeStatus::DrawPending)
                <p class="text-[13px] leading-relaxed text-ink">{!! __('backoffice.challenges.draw_explainer') !!}</p>

                <p class="mt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.published_seed') }}</p>
                <div class="mt-1.5 flex items-center gap-2.5">
                    <code class="flex-1 rounded border border-line bg-surface px-4 py-3 font-mono text-[15px] font-semibold tracking-wide text-ink">
                        {{ $challenge->draw_seed ?? '—' }}
                    </code>
                    @if ($canManage)
                        <x-button icon variant="secondary" class="size-[46px]" wire:click="regenerateSeed" target="regenerateSeed"
                                  :aria-label="__('backoffice.challenges.regenerate_seed')" :title="__('backoffice.challenges.regenerate_seed')">
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                        </x-button>
                    @endif
                </div>

                <div class="mt-4 rounded bg-surface p-4 text-[13px] leading-7">
                    <p>{{ __('backoffice.challenges.frozen_pool') }} : <b>{{ $this->listSummary() }}</b></p>
                    <p>
                        {{ __('backoffice.challenges.snapshot_hash') }} :
                        <span class="font-mono text-xs">{{ $challenge->draw_pool_hash ? 'sha256:'.$challenge->draw_pool_hash : __('backoffice.challenges.awaiting_close') }}</span>
                    </p>
                    <p>{{ __('backoffice.challenges.frozen_on') }} : <b>{{ $challenge->period_end->translatedFormat('j M \à H\hi') }}</b></p>
                </div>

                @if ($canManage)
                    {{-- Tirage irréversible : gardé pendant l'aller-retour, et
                         inerte tant qu'aucune graine n'est publiée. --}}
                    <x-button class="mt-4 w-full justify-between" wire:click="executeDraw" target="executeDraw" :disabled="! $challenge->draw_seed">
                        {{ $challenge->type === ChallengeType::Surprise ? __('backoffice.challenges.draw_random_winners') : __('backoffice.challenges.execute_draw') }}
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                    </x-button>
                @else
                    <x-banner tone="warn" class="mt-4">{{ __('backoffice.challenges.draw_restricted') }}</x-banner>
                @endif
            @endif
        </x-panel>
    @endif

    {{-- Progression / clôture de la période --}}
    <x-panel :title="$progress['title']">
        <x-slot:actions>
            <p class="text-xs text-muted">{{ $progress['caption'] }}</p>
        </x-slot:actions>

        <div class="h-[6px] overflow-hidden rounded-full bg-neutral-bg"
             role="progressbar" aria-valuenow="{{ (int) $progress['percent'] }}"
             aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ $progress['title'] }}">
            <div class="h-full rounded-full bg-primary transition-[width] duration-500 motion-reduce:transition-none" style="width: {{ $progress['percent'] }}%"></div>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-3">
            @foreach ($stats as $stat)
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $stat['label'] }}</p>
                    <p class="mt-1 text-[26px] font-semibold leading-none tracking-tight tabular-nums {{ $stat['tone'] }}">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>
    </x-panel>

    {{-- Liste des participants, dépliable --}}
    <x-panel :title="$this->listTitle()" :subtitle="$this->listSummary()" flush>
        <x-slot:actions>
            <x-button variant="secondary" size="sm" wire:click="$toggle('listOpen')" :aria-expanded="$listOpen ? 'true' : 'false'">
                {{ $listOpen ? __('backoffice.challenges.hide_list') : __('backoffice.challenges.show_list') }}
                <svg @class(['size-3.5 text-muted transition-transform', 'rotate-180' => $listOpen])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6"/>
                </svg>
            </x-button>
        </x-slot:actions>

        @if ($listOpen)
            @php $rows = $this->listRows(); @endphp

            <x-toolbar class="px-5 py-3.5">
                <x-field :label="__('backoffice.challenges.search_driver')" name="listSearch" type="search" label-hidden
                         wire:model.live.debounce.400ms="listSearch" :placeholder="__('backoffice.challenges.search_driver')" class="min-w-[240px] flex-1" />

                @if ($challenge->type === ChallengeType::Leaderboard)
                    @foreach ([
                        'tous' => __('backoffice.challenges.all'),
                        'gagnants' => __('backoffice.challenges.in_top', ['top' => (int) ($challenge->winners_count ?? 0)]),
                        'hors' => __('backoffice.challenges.outside_ranking'),
                    ] as $key => $label)
                        <x-chip-filter wire:key="list-filter-{{ $key }}" wire:click="$set('listFilter', '{{ $key }}')" :active="$listFilter === $key">{{ $label }}</x-chip-filter>
                    @endforeach
                @endif
            </x-toolbar>

            <x-table class="border-t border-line" loading="listSearch,listFilter">
                <x-slot:head>
                    @if ($challenge->type === ChallengeType::Leaderboard)
                        <x-th>{{ __('backoffice.challenges.rank') }}</x-th>
                    @endif
                    <x-th>{{ __('backoffice.challenges.driver') }}</x-th>
                    <x-th align="right">{{ __('backoffice.challenges.orders') }}</x-th>
                    @if ($challenge->type === ChallengeType::Raffle)
                        <x-th align="right">{{ __('backoffice.challenges.tickets') }}</x-th>
                    @endif
                    <x-th>{{ __('backoffice.challenges.column_status') }}</x-th>
                </x-slot:head>

                @foreach ($rows as $row)
                    {{-- Une ligne gagnante est teintée *et* étiquetée : la couleur
                         seule ne se lit pas au lecteur d'écran. --}}
                    <tr wire:key="row-{{ $row['rank'] }}" @class(['transition-colors', 'bg-ok-bg/40' => $row['isWinner'], 'hover:bg-surface' => ! $row['isWinner']])>
                        @if ($challenge->type === ChallengeType::Leaderboard)
                            <x-td mono muted nowrap>n° {{ number_format($row['rank'], 0, ',', ' ') }}</x-td>
                        @endif
                        <x-td>
                            <b class="text-[13px] text-ink">{{ $row['name'] }}</b>
                            <span class="ml-2 font-mono text-[11px] text-muted">{{ $row['account'] }}</span>
                        </x-td>
                        <x-td align="right" nowrap class="text-[13px] font-semibold tabular-nums">{{ number_format($row['orders'], 0, ',', ' ') }}</x-td>
                        @if ($challenge->type === ChallengeType::Raffle)
                            <x-td align="right" nowrap class="text-[13px] font-semibold tabular-nums">{{ $row['tickets'] }}</x-td>
                        @endif
                        <x-td><x-badge :tone="$row['isWinner'] ? 'ok' : 'neutral'">{{ $row['label'] }}</x-badge></x-td>
                    </tr>
                @endforeach

                @if ($rows === [])
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :hint="__('backoffice.challenges.no_participant')" />
                    </x-slot:empty>
                @endif
            </x-table>

            @if ($challenge->status === ChallengeStatus::DrawPending || $drawDone)
                @php $pool = $this->frozenPoolRows(); @endphp
                @if ($pool !== [])
                    <div class="border-t border-line bg-surface p-5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.frozen_snapshot') }}</p>
                        <table class="mt-2.5 w-full border-collapse text-[13px]">
                            <tbody>
                                @foreach ($pool as $poolRow)
                                    <tr @class(['bg-ok-bg' => $poolRow['isWinner']])>
                                        <td class="border-b border-line px-2 py-2 text-ink">{{ $poolRow['name'] }}</td>
                                        <td class="border-b border-line px-2 py-2 text-right text-muted">{{ trans_choice('backoffice.challenges.tickets_count', $poolRow['tickets'], ['count' => $poolRow['tickets']]) }}</td>
                                        <td class="border-b border-line px-2 py-2 text-right font-mono text-xs text-muted">{{ $poolRow['range'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-2.5 text-xs leading-relaxed text-muted">{{ __('backoffice.challenges.snapshot_note') }}</p>
                    </div>
                @endif
            @endif
        @endif
    </x-panel>

    {{-- Gratifications --}}
    <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-muted">
        {{ $isRaffleOrSurprise ? __('backoffice.challenges.section_draw_and_rewards') : __('backoffice.challenges.section_rewards') }}
    </p>

    @if ($totalWinners === 0)
        <x-panel>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[13px] text-muted">{{ $this->emptyRewardsMessage() }}</p>
                <p class="text-xs text-muted">
                    {{ __('backoffice.challenges.committed_budget') }} : <b class="text-ink tabular-nums">{{ $this->committedBudget() }}</b>
                </p>
            </div>
        </x-panel>
    @else
        <x-banner tone="warn">{!! __('backoffice.challenges.manual_phase_notice') !!}</x-banner>

        <x-panel :title="__('backoffice.challenges.rewards')"
                 :subtitle="__('backoffice.challenges.deposited_ratio', ['credited' => $creditedCount, 'total' => $totalWinners])" flush>
            <x-slot:actions>
                <b class="text-[15px] text-ok-text tabular-nums">{{ $this->committedBudget() }}</b>
                @if ($canManage && $creditedCount < $totalWinners)
                    <x-button size="sm" wire:click="confirmAction('credit_all')" target="confirmAction">{{ __('backoffice.challenges.deposit_all') }}</x-button>
                @endif
            </x-slot:actions>

            <x-toolbar class="px-5 py-3.5">
                <x-field :label="__('backoffice.challenges.search_winner')" name="winnerSearch" type="search" label-hidden
                         wire:model.live.debounce.400ms="winnerSearch" :placeholder="__('backoffice.challenges.search_winner')" class="min-w-[240px] flex-1" />
                @foreach ([
                    'tous' => __('backoffice.challenges.all'),
                    'adeposer' => __('backoffice.challenges.to_deposit'),
                    'deposes' => __('backoffice.challenges.deposited'),
                ] as $key => $label)
                    <x-chip-filter wire:key="winner-filter-{{ $key }}" wire:click="$set('winnerFilter', '{{ $key }}')" :active="$winnerFilter === $key">{{ $label }}</x-chip-filter>
                @endforeach
            </x-toolbar>

            <x-table class="border-t border-line" loading="winnerSearch,winnerFilter">
                <x-slot:head>
                    <x-th>{{ __('backoffice.challenges.driver') }}</x-th>
                    <x-th>{{ __('backoffice.challenges.yango_account') }}</x-th>
                    <x-th>{{ __('backoffice.challenges.reward') }}</x-th>
                    <x-th>{{ __('backoffice.challenges.column_status') }}</x-th>
                    <x-th>{{ __('backoffice.challenges.deposited_by') }}</x-th>
                    <x-th><span class="sr-only">{{ __('backoffice.challenges.mark_credited') }}</span></x-th>
                </x-slot:head>

                @foreach ($winners as $winner)
                    <tr wire:key="winner-{{ $winner->id }}" class="transition-colors hover:bg-surface">
                        <x-td>
                            @if ($winner->rank)
                                <span class="mr-2 font-mono text-[11px] text-muted">n° {{ $winner->rank }}</span>
                            @endif
                            <b class="text-[13.5px] text-ink">{{ $winner->driver->fullName() }}</b>
                        </x-td>
                        <x-td mono muted nowrap class="text-xs">{{ $winner->driver->yango_id ?? '—' }}</x-td>
                        <x-td nowrap class="text-[13.5px] font-semibold">{{ $winner->prize?->name ?? number_format((int) $winner->amount, 0, ',', ' ').' FCFA' }}</x-td>
                        <x-td>
                            <x-badge :tone="$winner->credited ? 'ok' : 'warn'">
                                {{ $winner->credited ? __('backoffice.challenges.deposited_on_yango') : __('backoffice.challenges.to_deposit') }}
                            </x-badge>
                        </x-td>
                        <x-td muted class="text-[13px]">{{ $winner->creditedBy->name ?? '—' }}</x-td>
                        <x-td align="right" nowrap>
                            @if ($canManage && ! $winner->credited)
                                <x-button variant="secondary" size="sm" wire:click="markCredited('{{ $winner->id }}')" target="markCredited('{{ $winner->id }}')">
                                    {{ __('backoffice.challenges.mark_credited') }}
                                </x-button>
                            @endif
                        </x-td>
                    </tr>
                @endforeach

                @if ($winners->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :hint="__('backoffice.challenges.no_winner_matches')" />
                    </x-slot:empty>
                @endif
            </x-table>
        </x-panel>
    @endif

    {{-- Modale plutôt que `wire:confirm` : le dialogue natif bloque
         l'automatisation navigateur (cf. le module Recharges). --}}
    @if ($pendingAction !== null)
        @php
            $isClosePeriod = $pendingAction === 'close_period';
            $confirmLabel = $isClosePeriod
                ? __('backoffice.challenges.close_period_now')
                : __('backoffice.challenges.deposit_all_on_yango');
            $confirmBody = $isClosePeriod
                ? __('backoffice.challenges.confirm_close_period')
                : __('backoffice.challenges.confirm_credit_all');
        @endphp
        <x-confirm close="cancelAction" :action="$isClosePeriod ? 'closePeriod' : 'creditAll'"
                   :title="$confirmLabel" :body="$confirmBody" :confirm-label="$confirmLabel" />
    @endif

    <livewire:challenges.wizard wire:key="challenge-wizard-show" />
</div>
