@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeType;
    use App\Enums\PrizeNature;
@endphp
{{--
    Étape 4 : nature du prix et mode d'attribution.

    Lit `$prizeNature`, `$rewardAmount`, `$prizeId`, `$lots`, `$awardMode`,
    `$modes`, `$winnersCount`, `$populationMax`, `$natures`, `$topNEnabled`
    et `$topN`.
--}}

<p class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.prize_nature') }}</p>
<div class="mt-2 grid gap-3 sm:grid-cols-2">
    @foreach ($natures as $case)
        <button type="button" wire:click="$set('prizeNature', '{{ $case->value }}')"
                @class([
                    'rounded border-2 p-3.5 text-left transition-colors',
                    'border-primary bg-primary-tint' => $prizeNature === $case->value,
                    'border-line bg-card hover:border-input' => $prizeNature !== $case->value,
                ])>
            <b class="block text-sm text-ink">{{ $case->label() }}</b>
            <span class="mt-0.5 block text-xs leading-relaxed text-muted">{{ $case->description() }}</span>
        </button>
    @endforeach
</div>

@if ($prizeNature === PrizeNature::Cash->value)
    <label for="wizard-amount" class="mt-4 block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.amount_per_winner') }}</label>
    <input wire:model.live="rewardAmount" id="wizard-amount" type="number" min="1"
           class="mt-1.5 block w-full max-w-[260px] rounded border border-input px-3 py-2.5 text-sm focus:border-primary">
    @error('rewardAmount') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
@else
    <div class="mt-4 flex items-baseline justify-between gap-3">
        <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.pick_lot') }}</p>
        {{-- Renvoi discret vers l'onglet Lots : créer un
             lot reste une opération d'administration, pas
             une étape de l'assistant. --}}
        <a href="{{ route('bo.challenges.prizes') }}" wire:navigate
           class="text-xs font-semibold text-primary-text hover:underline">
            {{ __('backoffice.challenges.manage_lots') }}
        </a>
    </div>
    @forelse ($lots as $lot)
        @if ($loop->first)
            <div class="mt-2 grid gap-2.5 sm:grid-cols-2">
        @endif
            <button type="button" wire:click="$set('prizeId', '{{ $lot->id }}')"
                    @class([
                        'flex items-center gap-3 rounded border-2 p-2.5 text-left transition-colors',
                        'border-primary bg-primary-tint' => $prizeId === $lot->id,
                        'border-line hover:border-input' => $prizeId !== $lot->id,
                    ])>
                @if ($lot->photo_url)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($lot->photo_url) }}" alt=""
                         class="size-10 shrink-0 rounded border border-line object-cover">
                @else
                    <span class="size-10 shrink-0 rounded border border-line bg-surface"></span>
                @endif
                <b class="text-sm text-ink">{{ $lot->name }}</b>
            </button>
        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="mt-2 rounded border border-line bg-surface px-4 py-3 text-[13px] text-muted">
            {{ __('backoffice.challenges.no_lot_yet') }}
        </p>
    @endforelse
    @error('prizeId') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
@endif

@if ($type !== ChallengeType::Surprise->value)
    <p class="mt-4 border-t border-line pt-4 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.award_mode') }}</p>
    <div class="mt-2 space-y-2">
        @foreach ($modes as $case)
            @php $isSelected = $awardMode === $case->value; @endphp
            {{-- Enveloppe non interactive : le nombre de
                 gagnants se règle DANS la carte « prix
                 collectif », ce qui interdit d'imbriquer
                 un champ dans un <button>. --}}
            <div @class([
                'overflow-hidden rounded border-2 transition-colors',
                'border-primary bg-primary-tint' => $isSelected,
                'border-line bg-card hover:border-input' => ! $isSelected,
            ])>
                <button type="button" wire:click="$set('awardMode', '{{ $case->value }}')"
                        class="flex w-full items-start gap-3 p-3.5 text-left">
                    <span @class([
                        'mt-0.5 flex size-[18px] shrink-0 rounded-full border-2',
                        'border-primary bg-primary' => $isSelected,
                        'border-input bg-line' => ! $isSelected,
                    ])></span>
                    <span class="min-w-0">
                        <b class="block text-sm text-ink">{{ $case->label() }}</b>
                        <span class="mt-0.5 block text-xs leading-relaxed text-muted">{{ $case->description() }}</span>
                    </span>
                </button>

                {{-- Le nombre de gagnants n'a de sens que
                     pour un prix collectif : il se règle
                     dans sa carte, décalé comme un
                     réglage de cette option. --}}
                @if ($case === AwardMode::Collective && $isSelected)
                    <div class="border-t border-primary/20 bg-card/60 py-3.5 pl-[42px] pr-4">
                        <label for="wizard-winners" class="block text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">
                            {{ __('backoffice.challenges.winners_count') }}
                        </label>
                        <input wire:model.live="winnersCount" id="wizard-winners" type="number" min="1"
                               class="mt-1.5 block w-[120px] rounded border border-input bg-card px-3 py-2 text-center text-[13.5px] font-bold text-ink focus:border-primary">
                        <p class="mt-1.5 text-xs leading-relaxed text-muted">
                            {{ $topNEnabled ? __('backoffice.challenges.winners_aligned', ['top' => $topN]) : __('backoffice.challenges.winners_fallback') }}
                        </p>
                        @error('winnersCount') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

@if ($type === ChallengeType::Surprise->value)
    <label for="wizard-population" class="mt-4 block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.population_max') }}</label>
    <input wire:model.live="populationMax" id="wizard-population" type="number" min="1"
           class="mt-1.5 block w-full max-w-[260px] rounded border border-input px-3 py-2.5 text-sm focus:border-primary">
    @error('populationMax') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
@endif

