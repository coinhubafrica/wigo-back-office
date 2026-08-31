@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeStatus;
    use App\Enums\ChallengeType;
    use App\Enums\PrizeNature;

    $isRaffleOrSurprise = $challenge->type !== ChallengeType::Leaderboard;
    $drawDone = $challenge->drawn_at !== null;
@endphp
<div class="flex flex-col gap-4">
    <a href="{{ route(\App\Enums\BackOfficeModule::Challenges->route()) }}" wire:navigate
       class="flex w-fit items-center gap-2 text-sm font-semibold text-ink hover:text-primary-text">
        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        {{ __('backoffice.challenges.all_challenges') }}
    </a>

    {{-- En-tête : identité, résumé, actions dépendantes du statut --}}
    <div class="overflow-hidden rounded border border-line bg-card">
        <div class="flex flex-wrap items-start justify-between gap-5 p-5">
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2.5 text-[11.5px] font-bold uppercase tracking-[0.1em]">
                    <span class="text-primary-text">{{ $challenge->type->label() }}</span>
                    <span class="text-muted">·</span>
                    <span class="font-mono font-medium normal-case tracking-normal text-muted">{{ $challenge->reference }}</span>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold normal-case tracking-normal {{ $challenge->status->badgeClasses() }}">
                        {{ $challenge->status->label() }}
                    </span>
                </p>

                <h2 class="mt-2 text-[26px] font-bold leading-tight text-ink">{{ $challenge->name }}</h2>

                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-muted">
                    {{ $challenge->criteriaSummary() }} — {{ $challenge->prizeLabel() }}
                    @if ($challenge->award_mode === AwardMode::SingleWinner)
                        {{ __('backoffice.challenges.for_single_winner') }}
                    @else
                        {{ trans_choice('backoffice.challenges.for_winners', $challenge->effectiveWinnersCount(), ['count' => $challenge->effectiveWinnersCount()]) }}
                    @endif
                </p>
            </div>

            <div class="flex w-full max-w-[290px] shrink-0 flex-col gap-2.5">
                @if ($challenge->status === ChallengeStatus::PendingApproval)
                    @can('approveSurpriseChallenge')
                        <button wire:click="approve"
                                class="flex items-center justify-center gap-2 rounded bg-ok-text px-3.5 py-2.5 text-[13px] font-bold text-white hover:brightness-110">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ __('backoffice.challenges.approve_challenge') }}
                        </button>
                        <button wire:click="$toggle('showRejectForm')"
                                class="rounded border border-err-text px-3.5 py-2.5 text-[13px] font-bold text-err-text hover:bg-err-bg">
                            {{ __('backoffice.challenges.reject') }}
                        </button>
                    @endcan
                @elseif ($challenge->status === ChallengeStatus::Active && $canManage)
                    <button wire:click="confirmAction('close_period')"
                            class="rounded border border-input bg-line px-3.5 py-2.5 text-left text-[13px] font-bold text-ink hover:bg-input">
                        {{ __('backoffice.challenges.close_period_now') }}
                    </button>
                @elseif ($challenge->status === ChallengeStatus::PayoutPending && $canManage && $creditedCount < $totalWinners)
                    <button wire:click="confirmAction('credit_all')"
                            class="rounded bg-ok-text px-3.5 py-2.5 text-left text-[13px] font-bold text-white hover:brightness-110">
                        {{ __('backoffice.challenges.deposit_all_on_yango') }}
                    </button>
                @endif

                <button type="button"
                        x-on:click="$dispatch('open-challenge-wizard', { template: @js($this->duplicateTemplateKey()) })"
                        class="flex items-center gap-2.5 rounded border border-line bg-card px-3.5 py-2.5 text-[13px] font-bold text-ink hover:bg-surface">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>
                    </svg>
                    {{ __('backoffice.challenges.duplicate_next_period') }}
                </button>
            </div>
        </div>

        @if ($showRejectForm)
            <form wire:submit="reject" class="border-t border-line bg-err-bg/40 p-5">
                <label for="rejection" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">
                    {{ __('backoffice.challenges.rejection_reason') }}
                </label>
                <input wire:model="rejectionReason" id="rejection" type="text" required
                       placeholder="{{ __('backoffice.challenges.rejection_reason_placeholder') }}"
                       class="mt-1.5 block w-full max-w-xl rounded border border-input px-3 py-2.5 text-sm focus:border-primary">
                @error('rejectionReason') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                <div class="mt-3 flex gap-2">
                    <button type="submit" class="rounded bg-err-text px-4 py-2 text-[13px] font-bold text-white">
                        {{ __('backoffice.challenges.confirm_reject') }}
                    </button>
                    <button type="button" wire:click="$toggle('showRejectForm')" class="rounded border border-line px-4 py-2 text-[13px] text-muted hover:bg-surface">
                        {{ __('backoffice.challenges.cancel') }}
                    </button>
                </div>
            </form>
        @endif

        {{-- Définition en quatre cases --}}
        <div class="grid border-t border-line sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($definition as $cell)
                <div class="border-b border-line p-4 lg:border-b-0 lg:[&:not(:last-child)]:border-r">
                    <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ $cell['label'] }}</p>
                    <p class="mt-1.5 text-[15px] font-bold text-ink">{{ $cell['value'] }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-muted">{{ $cell['caption'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Chevron pivotant en SVG plutôt que ▲/▼, et `aria-expanded` : l'état
             déplié n'était porté que par le glyphe. --}}
        <button wire:click="$toggle('definitionOpen')"
                aria-expanded="{{ $definitionOpen ? 'true' : 'false' }}"
                class="flex w-full items-center gap-2 border-t border-line bg-surface px-5 py-3 text-left text-[13px] font-semibold text-ink hover:bg-line/60">
            {{ $definitionOpen ? __('backoffice.challenges.hide_definition') : __('backoffice.challenges.show_definition') }}
            <svg @class(['size-3.5 text-muted transition-transform', 'rotate-180' => $definitionOpen])
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </button>

        @if ($definitionOpen)
            <div class="grid gap-6 border-t border-line bg-surface p-5 sm:grid-cols-2">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ __('backoffice.challenges.column_criteria') }}</p>
                    <dl class="mt-2 space-y-2 text-[13px]">
                        @forelse ($challenge->activeCriteria() as $criterion)
                            <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                                <dt class="text-muted">{{ $criterion['label'] }}</dt>
                                <dd class="shrink-0 font-bold text-ink">{{ $criterion['value'] }}</dd>
                            </div>
                        @empty
                            <p class="text-muted">{{ __('backoffice.challenges.no_criteria_defined') }}</p>
                        @endforelse
                    </dl>
                </div>

                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ __('backoffice.challenges.prize') }}</p>
                    <dl class="mt-2 space-y-2 text-[13px]">
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.prize_nature') }}</dt>
                            <dd class="font-bold text-ink">
                                {{ $challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.physical_prize') : __('backoffice.challenges.cash_transfer') }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.value') }}</dt>
                            <dd class="font-bold text-ink">{{ $challenge->prizeLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2">
                            <dt class="text-muted">{{ __('backoffice.challenges.award') }}</dt>
                            <dd class="font-bold text-ink">{{ $challenge->prizeSubLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted">{{ __('backoffice.challenges.created_by') }}</dt>
                            <dd class="font-bold text-ink">{{ $challenge->createdBy->name ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @endif
    </div>

    {{-- Tirage : panneau mis en avant quand il reste à exécuter --}}
    @if ($isRaffleOrSurprise)
        <div class="overflow-hidden rounded border bg-card {{ $challenge->status === ChallengeStatus::DrawPending ? 'border-primary' : 'border-line' }}">
            <div class="border-b border-line px-5 py-3.5">
                <p class="text-[14.5px] font-bold text-ink">
                    @if ($drawDone)
                        {{ __('backoffice.challenges.draw_result') }}
                    @elseif ($challenge->status === ChallengeStatus::DrawPending)
                        {{ __('backoffice.challenges.draw_to_run') }}
                    @else
                        {{ __('backoffice.challenges.draw_awaiting_close') }}
                    @endif
                </p>
            </div>

            @if ($drawDone)
                <div class="flex flex-wrap items-center gap-5 p-5">
                    @if ($challenge->prize?->photo_url)
                        <img src="{{ $challenge->prize->photo_url }}" alt="{{ $challenge->prize->name }}"
                             class="size-[104px] shrink-0 rounded border border-line object-cover">
                    @endif
                    <div class="min-w-0">
                        @foreach ($challenge->winners as $winner)
                            <div @class(['mt-4 border-t border-line pt-4' => ! $loop->first])>
                                @if ($winner->winning_range_number)
                                    <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted">
                                        {{ __('backoffice.challenges.winning_ticket') }} · <span class="font-mono">n° {{ number_format($winner->winning_range_number, 0, ',', ' ') }}</span>
                                    </p>
                                @endif
                                <p class="mt-1 text-xl font-bold text-ink">{{ $winner->driver->fullName() }}</p>
                                <p class="mt-0.5 text-sm font-bold text-ok-text">{{ $challenge->prizeLabel() }}</p>
                                <span class="mt-2 inline-block rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $winner->credited ? 'bg-ok-bg text-ok-text' : 'bg-warn-bg text-warn-text' }}">
                                    {{ $winner->credited
                                        ? ($challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.prize_handed_over') : __('backoffice.challenges.credited'))
                                        : ($challenge->prize_nature === PrizeNature::PhysicalItem ? __('backoffice.challenges.prize_to_hand_over') : __('backoffice.challenges.bonus_to_deposit')) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($challenge->status === ChallengeStatus::DrawPending)
                <div class="p-5">
                    <p class="text-[13px] leading-relaxed text-ink">{!! __('backoffice.challenges.draw_explainer') !!}</p>

                    <p class="mt-4 text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ __('backoffice.challenges.published_seed') }}</p>
                    <div class="mt-1.5 flex items-center gap-2.5">
                        <code class="flex-1 rounded border border-line bg-surface px-4 py-3 font-mono text-[15px] font-semibold tracking-wide text-ink">
                            {{ $challenge->draw_seed ?? '—' }}
                        </code>
                        @if ($canManage)
                            <button wire:click="regenerateSeed" title="{{ __('backoffice.challenges.regenerate_seed') }}"
                                    class="flex size-[46px] shrink-0 items-center justify-center rounded border border-line bg-card text-muted hover:border-primary hover:text-primary">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>
                                </svg>
                            </button>
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
                        <button wire:click="executeDraw" @disabled(! $challenge->draw_seed)
                                class="mt-4 flex w-full items-center justify-between rounded bg-primary px-5 py-3.5 text-[14px] font-bold text-white hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50">
                            {{ $challenge->type === ChallengeType::Surprise ? __('backoffice.challenges.draw_random_winners') : __('backoffice.challenges.execute_draw') }}
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    @else
                        <p class="mt-4 rounded bg-warn-bg px-4 py-3 text-[13px] text-warn-text">
                            {{ __('backoffice.challenges.draw_restricted') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Progression / clôture de la période --}}
    <div class="rounded border border-line bg-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <p class="text-[14.5px] font-bold text-ink">{{ $progress['title'] }}</p>
            <p class="text-xs text-muted">{{ $progress['caption'] }}</p>
        </div>

        <div class="mt-3 h-[6px] overflow-hidden rounded-full bg-line"
             role="progressbar" aria-valuenow="{{ (int) $progress['percent'] }}"
             aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ $progress['title'] }}">
            <div class="h-full rounded-full bg-primary transition-[width] duration-500 motion-reduce:transition-none" style="width: {{ $progress['percent'] }}%"></div>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-3">
            @foreach ($stats as $stat)
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ $stat['label'] }}</p>
                    <p class="mt-1 text-[26px] font-bold leading-none {{ $stat['tone'] }}">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Liste des participants, dépliable --}}
    <div class="overflow-hidden rounded border border-line bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <p class="text-[14.5px] font-bold text-ink">
                {{ $this->listTitle() }}
                <span class="ml-1.5 text-sm font-normal text-muted">{{ $this->listSummary() }}</span>
            </p>
            <button wire:click="$toggle('listOpen')"
                    aria-expanded="{{ $listOpen ? 'true' : 'false' }}"
                    class="flex items-center gap-2 rounded border border-line bg-card px-3.5 py-2 text-[13px] font-bold text-ink hover:bg-surface">
                {{ $listOpen ? __('backoffice.challenges.hide_list') : __('backoffice.challenges.show_list') }}
                <svg @class(['size-3.5 text-muted transition-transform', 'rotate-180' => $listOpen])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6"/>
                </svg>
            </button>
        </div>

        @if ($listOpen)
            @php $rows = $this->listRows(); @endphp

            <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3.5">
                <input wire:model.live.debounce.400ms="listSearch" type="search"
                       placeholder="{{ __('backoffice.challenges.search_driver') }}"
                       class="min-w-[240px] flex-1 rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">

                @if ($challenge->type === ChallengeType::Leaderboard)
                    @foreach ([
                        'tous' => __('backoffice.challenges.all'),
                        'gagnants' => __('backoffice.challenges.in_top', ['top' => (int) ($challenge->winners_count ?? 0)]),
                        'hors' => __('backoffice.challenges.outside_ranking'),
                    ] as $key => $label)
                        {{-- `aria-pressed` : la sélection est signalée par la couleur
                             seule, invisible pour un lecteur d'écran sans cet état. --}}
                        <button wire:click="$set('listFilter', '{{ $key }}')"
                                aria-pressed="{{ $listFilter === $key ? 'true' : 'false' }}"
                                @class([
                                    'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                    'border-primary bg-primary-tint text-primary-text' => $listFilter === $key,
                                    'border-line bg-card text-ink hover:border-primary' => $listFilter !== $key,
                                ])>{{ $label }}</button>
                    @endforeach
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-surface">
                            @if ($challenge->type === ChallengeType::Leaderboard)
                                <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.rank') }}</th>
                            @endif
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.driver') }}</th>
                            <th class="border-y border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.orders') }}</th>
                            @if ($challenge->type === ChallengeType::Raffle)
                                <th class="border-y border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.tickets') }}</th>
                            @endif
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="row-{{ $row['rank'] }}" @class(['bg-ok-bg/40' => $row['isWinner']])>
                                @if ($challenge->type === ChallengeType::Leaderboard)
                                    <td class="border-b border-line px-4 py-2.5 font-mono text-xs text-muted">n° {{ number_format($row['rank'], 0, ',', ' ') }}</td>
                                @endif
                                <td class="border-b border-line px-4 py-2.5">
                                    <b class="text-[13px] text-ink">{{ $row['name'] }}</b>
                                    <span class="ml-2 font-mono text-[11px] text-muted">{{ $row['account'] }}</span>
                                </td>
                                <td class="border-b border-line px-4 py-2.5 text-right text-[13px] font-semibold text-ink">{{ number_format($row['orders'], 0, ',', ' ') }}</td>
                                @if ($challenge->type === ChallengeType::Raffle)
                                    <td class="border-b border-line px-4 py-2.5 text-right text-[13px] font-semibold text-ink">{{ $row['tickets'] }}</td>
                                @endif
                                <td class="border-b border-line px-4 py-2.5">
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $row['isWinner'] ? 'bg-ok-bg text-ok-text' : 'bg-neutral-bg text-neutral-text' }}">
                                        {{ $row['label'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">
                                    {{ __('backoffice.challenges.no_participant') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($challenge->status === ChallengeStatus::DrawPending || $drawDone)
                @php $pool = $this->frozenPoolRows(); @endphp
                @if ($pool !== [])
                    <div class="border-t border-line bg-surface p-5">
                        <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">{{ __('backoffice.challenges.frozen_snapshot') }}</p>
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
    </div>

    {{-- Gratifications --}}
    <p class="mt-2 text-[11.5px] font-extrabold uppercase tracking-[0.16em] text-muted">
        {{ $isRaffleOrSurprise ? __('backoffice.challenges.section_draw_and_rewards') : __('backoffice.challenges.section_rewards') }}
    </p>

    @if ($totalWinners === 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-line bg-card p-5">
            <p class="text-[13px] text-muted">{{ $this->emptyRewardsMessage() }}</p>
            <p class="text-xs text-muted">
                {{ __('backoffice.challenges.committed_budget') }} : <b class="text-ink">{{ $this->committedBudget() }}</b>
            </p>
        </div>
    @else
        <div class="rounded border-l-[3px] border-warn-text bg-warn-bg px-4 py-3.5 text-[12.5px] leading-relaxed text-warn-text">
            {!! __('backoffice.challenges.manual_phase_notice') !!}
        </div>

        <div class="overflow-hidden rounded border border-line bg-card">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <p class="text-[14.5px] font-bold text-ink">
                    {{ __('backoffice.challenges.rewards') }}
                    <span class="ml-1.5 text-sm font-normal text-muted">
                        {{ __('backoffice.challenges.deposited_ratio', ['credited' => $creditedCount, 'total' => $totalWinners]) }}
                    </span>
                </p>

                <div class="flex items-center gap-3">
                    <b class="text-[15px] text-ok-text">{{ $this->committedBudget() }}</b>
                    @if ($canManage && $creditedCount < $totalWinners)
                        <button wire:click="confirmAction('credit_all')"
                                class="rounded bg-primary px-4 py-2.5 text-[13px] font-bold text-white hover:bg-primary-hover">
                            {{ __('backoffice.challenges.deposit_all') }}
                        </button>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3.5">
                <input wire:model.live.debounce.400ms="winnerSearch" type="search"
                       placeholder="{{ __('backoffice.challenges.search_winner') }}"
                       class="min-w-[240px] flex-1 rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">

                @foreach ([
                    'tous' => __('backoffice.challenges.all'),
                    'adeposer' => __('backoffice.challenges.to_deposit'),
                    'deposes' => __('backoffice.challenges.deposited'),
                ] as $key => $label)
                    {{-- `aria-pressed` : la sélection est signalée par la couleur
                         seule, invisible pour un lecteur d'écran sans cet état. --}}
                    <button wire:click="$set('winnerFilter', '{{ $key }}')"
                            aria-pressed="{{ $winnerFilter === $key ? 'true' : 'false' }}"
                            @class([
                                'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                'border-primary bg-primary-tint text-primary-text' => $winnerFilter === $key,
                                'border-line bg-card text-ink hover:border-primary' => $winnerFilter !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-surface">
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.driver') }}</th>
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.yango_account') }}</th>
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.reward') }}</th>
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.column_status') }}</th>
                            <th class="border-y border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.challenges.deposited_by') }}</th>
                            <th class="border-y border-line px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($winners as $winner)
                            <tr wire:key="winner-{{ $winner->id }}">
                                <td class="border-b border-line px-4 py-3">
                                    @if ($winner->rank)
                                        <span class="mr-2 font-mono text-[11px] text-muted">n° {{ $winner->rank }}</span>
                                    @endif
                                    <b class="text-[13.5px] text-ink">{{ $winner->driver->fullName() }}</b>
                                </td>
                                <td class="border-b border-line px-4 py-3 font-mono text-xs text-muted">{{ $winner->driver->yango_id ?? '—' }}</td>
                                <td class="border-b border-line px-4 py-3 text-[13.5px] font-bold text-ink">
                                    {{ $winner->prize?->name ?? number_format((int) $winner->amount, 0, ',', ' ').' FCFA' }}
                                </td>
                                <td class="border-b border-line px-4 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $winner->credited ? 'bg-ok-bg text-ok-text' : 'bg-warn-bg text-warn-text' }}">
                                        {{ $winner->credited ? __('backoffice.challenges.deposited_on_yango') : __('backoffice.challenges.to_deposit') }}
                                    </span>
                                </td>
                                <td class="border-b border-line px-4 py-3 text-[13px] text-muted">{{ $winner->creditedBy->name ?? '—' }}</td>
                                <td class="border-b border-line px-4 py-3 text-right">
                                    @if ($canManage && ! $winner->credited)
                                        <button wire:click="markCredited('{{ $winner->id }}')"
                                                class="rounded border border-line px-3 py-1.5 text-[11.5px] font-semibold text-ink hover:bg-surface">
                                            {{ __('backoffice.challenges.mark_credited') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-muted">
                                    {{ __('backoffice.challenges.no_winner_matches') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
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
        <x-modal close="cancelAction" max-width="max-w-sm" :label="$confirmBody">
            <div class="px-5 pb-4 pt-5">
                <p class="text-sm font-semibold text-ink">{{ $confirmLabel }}</p>
                <p class="mt-1.5 text-sm text-muted">{{ $confirmBody }}</p>
            </div>
            <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                <button wire:click="cancelAction" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.challenges.cancel') }}
                </button>
                <button wire:click="{{ $isClosePeriod ? 'closePeriod' : 'creditAll' }}"
                        @class([
                            'rounded px-4 py-2 text-sm font-semibold text-white',
                            'bg-primary hover:bg-primary-hover' => $isClosePeriod,
                            'bg-ok-text hover:brightness-110' => ! $isClosePeriod,
                        ])>
                    {{ $confirmLabel }}
                </button>
            </div>
        </x-modal>
    @endif

    <livewire:challenges.wizard wire:key="challenge-wizard-show" />
</div>
