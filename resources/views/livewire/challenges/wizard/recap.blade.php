@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeType;
    use App\Enums\PrizeNature;
@endphp
{{--
    Récapitulatif permanent, affiché à toutes les étapes.

    Fond légèrement plus dense et bord marqué, pour se distinguer du
    formulaire sans basculer en sombre. Lit l'ensemble des propriétés de
    saisie ainsi que `$this->estimatedEligibles()` et `$this->maximumCost()`.
--}}

<aside class="border-l-2 border-line bg-surface px-5 py-5">
    <p class="text-[11.5px] font-extrabold uppercase tracking-[0.16em] text-muted">{{ __('backoffice.challenges.recap') }}</p>

    <dl class="mt-3.5 space-y-3 text-[13px]">
        <div class="border-b border-line pb-3">
            <dt class="text-muted">{{ __('backoffice.challenges.column_type') }}</dt>
            <dd class="mt-0.5 font-bold text-primary-text">{{ ChallengeType::from($type)->label() }}</dd>
        </div>
        <div class="border-b border-line pb-3">
            <dt class="text-muted">{{ __('backoffice.challenges.field_name') }}</dt>
            <dd class="mt-0.5 font-bold text-ink">{{ trim($name) !== '' ? $name : __('backoffice.challenges.to_be_filled') }}</dd>
        </div>
        <div class="border-b border-line pb-3">
            <dt class="text-muted">{{ __('backoffice.challenges.column_criteria') }}</dt>
            <dd class="mt-0.5 font-bold text-ink">
                @php
                    $summary = $this->isTicketBasedRaffle()
                        ? '1 ticket / '.$minOrders.' courses'
                        : collect([
                            $minOrdersEnabled ? '≥ '.$minOrders : null,
                            $topNEnabled && $type === ChallengeType::Leaderboard->value ? 'Top '.$topN : null,
                            $minAcceptanceRateEnabled ? '≥ '.$minAcceptanceRate.' %' : null,
                            $minRatingEnabled ? '≥ '.$minRating.' / 5' : null,
                            $minActiveDaysEnabled ? '≥ '.$minActiveDays.' j' : null,
                        ])->filter()->implode(' · ');
                @endphp
                {{ $summary !== '' ? $summary : __('backoffice.challenges.no_criteria') }}
            </dd>
        </div>
        <div class="border-b border-line pb-3">
            <dt class="text-muted">{{ __('backoffice.challenges.column_period') }}</dt>
            <dd class="mt-0.5 font-bold text-ink">{{ \Illuminate\Support\Carbon::parse($periodStart)->translatedFormat('j M') }} → {{ \Illuminate\Support\Carbon::parse($periodEnd)->translatedFormat('j M') }}</dd>
        </div>
        <div class="border-b border-line pb-3">
            <dt class="text-muted">{{ __('backoffice.challenges.column_reward') }}</dt>
            <dd class="mt-0.5 font-bold text-ink">
                @if ($prizeNature === PrizeNature::Cash->value)
                    {{ number_format((int) $rewardAmount, 0, ',', ' ') }} FCFA
                @else
                    {{ $lots->firstWhere('id', $prizeId)?->name ?? __('backoffice.challenges.prize_to_pick') }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-muted">{{ __('backoffice.challenges.award') }}</dt>
            <dd class="mt-0.5 font-bold text-ink">
                @if ($type === ChallengeType::Surprise->value)
                    {{ trans_choice('backoffice.challenges.random_winners', (int) $populationMax, ['count' => (int) $populationMax]) }}
                @elseif ($awardMode === AwardMode::SingleWinner->value)
                    {{ __('backoffice.challenges.single_winner_draw') }}
                @else
                    {{ trans_choice('backoffice.challenges.winners', $this->effectiveWinnersCount(), ['count' => $this->effectiveWinnersCount()]) }}
                @endif
            </dd>
        </div>
    </dl>

    <div class="mt-4 rounded border border-input bg-card p-4">
        <p class="text-xs font-semibold text-muted">{{ __('backoffice.challenges.estimated_eligibles') }}</p>
        <p class="mt-1 text-2xl font-bold text-ink">{{ number_format($this->estimatedEligibles(), 0, ',', ' ') }}</p>

        <p class="mt-3 border-t border-line pt-3 text-xs font-semibold text-muted">{{ __('backoffice.challenges.maximum_cost') }}</p>
        <p class="mt-1 text-lg font-bold text-ok-text">{{ $this->maximumCost() }}</p>
    </div>
</aside>
