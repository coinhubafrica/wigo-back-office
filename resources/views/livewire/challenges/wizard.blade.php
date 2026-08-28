@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeType;
    use App\Enums\PrizeNature;
@endphp
{{-- L'écoute se fait sur `window` : les boutons de la liste émettent
     l'évènement côté navigateur pour éviter un re-rendu du parent. --}}
<div x-on:open-challenge-wizard.window="$wire.openWizard($event.detail?.template ?? null)">
    @if ($open)
        {{-- Échap ferme la modale ; le clic sur le fond aussi, mais pas sur
             le panneau (`stop`), sinon chaque interaction la refermerait. --}}
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/70 p-6 backdrop-blur-sm"
             wire:key="wizard-modal"
             x-on:keydown.escape.window="$wire.close()"
             x-on:click="$wire.close()">
            <div class="w-full max-w-4xl overflow-hidden rounded-lg bg-card shadow-[0_24px_64px_-12px_rgba(9,9,11,0.45)] ring-1 ring-ink/10" x-on:click.stop>
                {{-- Bandeau d'en-tête teinté : détache le titre du corps du
                     formulaire et ancre l'étape en cours. --}}
                <div class="flex items-start justify-between gap-4 border-b border-line bg-surface px-7 py-5">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-primary-text">
                            {{ __('backoffice.challenges.wizard_eyebrow', ['step' => $step, 'total' => \App\Livewire\Challenges\Wizard::LAST_STEP]) }}
                        </p>
                        <h2 class="mt-1.5 text-2xl font-bold tracking-tight text-ink">{{ $this->stepTitle() }}</h2>
                    </div>
                    <button type="button" wire:click="close"
                            class="flex size-8 shrink-0 items-center justify-center rounded border border-line bg-card text-lg leading-none text-muted transition-colors hover:border-input hover:text-ink"
                            aria-label="{{ __('backoffice.challenges.cancel') }}">&times;</button>
                </div>

                {{-- Onglets d'étape : l'étape courante est pleine, les étapes
                     franchies gardent la teinte primaire, les suivantes
                     restent neutres. --}}
                <div class="grid grid-cols-4 border-b border-line bg-card">
                    @foreach ($this->stepLabels() as $index => $label)
                        @php
                            $isCurrent = $step === $index + 1;
                            $isDone = $step > $index + 1;
                        @endphp
                        <div @class([
                            'flex items-center gap-2 border-t-[3px] px-4 py-3',
                            'border-primary bg-primary-tint' => $isCurrent,
                            'border-primary' => $isDone,
                            'border-line' => ! $isCurrent && ! $isDone,
                        ])>
                            <span @class([
                                'flex size-5 shrink-0 items-center justify-center rounded-full text-[10.5px] font-bold',
                                'bg-primary text-white' => $isCurrent || $isDone,
                                'bg-line text-muted' => ! $isCurrent && ! $isDone,
                            ])>{{ $isDone ? '✓' : $index + 1 }}</span>
                            <span @class([
                                'text-[13px] font-bold' => $isCurrent,
                                'text-[13px] font-semibold' => ! $isCurrent,
                                'text-primary-text' => $isCurrent,
                                'text-ink' => $isDone,
                                'text-muted' => ! $isCurrent && ! $isDone,
                            ])>{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="grid items-stretch gap-0 lg:grid-cols-[1fr_300px]">
                    <div class="min-h-[380px] px-7 py-5">
                        {{-- Étape 1 : type --}}
                        @if ($step === 1)
                            <p class="text-sm text-muted">{{ __('backoffice.challenges.step_type_lead') }}</p>

                            <div class="mt-4 space-y-2.5">
                                @foreach ($types as $case)
                                    @php $isSelected = $type === $case->value; @endphp
                                    {{-- Enveloppe non interactive : le ticketing du
                                         tirage se déplie DANS la carte, ce qui
                                         interdit d'imbriquer des contrôles dans un
                                         <button>. --}}
                                    <div @class([
                                        'overflow-hidden rounded border-2 transition-colors',
                                        'border-primary bg-primary-tint' => $isSelected,
                                        'border-line bg-card hover:border-input' => ! $isSelected,
                                    ])>
                                        <button type="button" wire:click="selectType('{{ $case->value }}')" class="block w-full p-4 text-left">
                                            <span class="flex items-start gap-3">
                                                <span @class([
                                                    'mt-0.5 flex size-[18px] shrink-0 items-center justify-center rounded-full border-2',
                                                    'border-primary bg-primary' => $isSelected,
                                                    'border-input bg-line' => ! $isSelected,
                                                ])></span>
                                                <span class="min-w-0">
                                                    <b class="block text-base text-ink">{{ $case->optionTitle() }}</b>
                                                    <span class="mt-1 block text-sm leading-relaxed text-muted">{{ $case->optionDescription() }}</span>
                                                    <span class="mt-1.5 block text-sm font-semibold text-primary-text">{{ $case->optionExample() }}</span>
                                                </span>
                                            </span>
                                        </button>

                                        {{-- Option du tirage : décalée et reliée
                                             par un filet à gauche, pour qu'elle se
                                             lise comme un réglage de cette carte
                                             et non comme un choix de même rang. --}}
                                        @if ($case === ChallengeType::Raffle && $isSelected)
                                            <div class="border-t border-primary/20 bg-card/60 py-3.5 pl-[46px] pr-4">
                                                <p class="mb-2 text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">
                                                    {{ __('backoffice.challenges.raffle_option') }}
                                                </p>
                                                <button type="button" wire:click="$toggle('isTicketBased')" class="flex w-full items-start gap-2.5 text-left">
                                                    <span @class([
                                                        'mt-0.5 flex size-[19px] shrink-0 items-center justify-center rounded border-2 text-xs font-bold text-white',
                                                        'border-primary bg-primary' => $isTicketBased,
                                                        'border-input bg-card' => ! $isTicketBased,
                                                    ])>{{ $isTicketBased ? '✓' : '' }}</span>
                                                    <span>
                                                        <b class="block text-[13.5px] text-ink">{{ __('backoffice.challenges.is_ticket_based') }}</b>
                                                        <span class="block text-xs leading-relaxed text-muted">{{ __('backoffice.challenges.is_ticket_based_hint') }}</span>
                                                    </span>
                                                </button>

                                                @if ($isTicketBased)
                                                    <div class="mt-3.5 flex flex-wrap items-center gap-2.5 border-t border-line pt-3.5 text-[13px] text-muted">
                                                        <span>{{ __('backoffice.challenges.one_ticket_per') }}</span>
                                                        <input wire:model.live="minOrders" type="number" min="1"
                                                               class="w-[92px] rounded border border-input bg-card px-2.5 py-1.5 text-center text-[13.5px] font-bold text-ink focus:border-primary focus:outline-none">
                                                        <span>{{ __('backoffice.challenges.unit_orders') }}</span>
                                                    </div>
                                                    @error('minOrders') <p class="mt-1.5 text-sm text-err-text">{{ $message }}</p> @enderror
                                                    <p class="mt-2 text-xs leading-relaxed text-muted">{{ __('backoffice.challenges.ticket_ratio_note') }}</p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                        @endif

                        {{-- Étape 2 : critères --}}
                        @if ($step === 2)
                            <label for="wizard-name" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">
                                {{ __('backoffice.challenges.field_name') }}
                            </label>
                            <input wire:model="name" id="wizard-name" type="text" placeholder="{{ $this->suggestedName() }}"
                                   class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm placeholder:text-muted focus:border-primary focus:outline-none">
                            @error('name') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror

                            @if ($this->isTicketBasedRaffle())
                                {{-- Le seul critère est la tranche de courses par
                                     ticket, déjà réglée à l'étape 1. --}}
                                <div class="mt-4 rounded border-2 border-primary bg-primary-tint p-4">
                                    <p class="text-[13.5px] font-bold text-ink">{{ __('backoffice.challenges.ticket_single_criterion') }}</p>
                                    <p class="mt-1.5 text-[13px] leading-relaxed text-ink/80">
                                        {{ __('backoffice.challenges.ticket_single_criterion_body', ['count' => $minOrders]) }}
                                    </p>
                                </div>
                            @else
                            <p class="mt-4 border-t border-line pt-4 text-sm text-muted">{{ __('backoffice.challenges.step_criteria_lead') }}</p>

                            <div class="mt-3 space-y-2">
                                @foreach ([
                                    ['key' => 'minOrders', 'toggle' => 'minOrdersEnabled', 'label' => __('backoffice.challenges.crit_orders'), 'hint' => __('backoffice.challenges.crit_orders_hint'), 'prefix' => __('backoffice.challenges.at_least'), 'unit' => __('backoffice.challenges.unit_orders'), 'on' => $minOrdersEnabled],
                                    ['key' => 'topN', 'toggle' => 'topNEnabled', 'label' => __('backoffice.challenges.crit_top_n'), 'hint' => __('backoffice.challenges.crit_top_n_hint'), 'prefix' => 'Top', 'unit' => __('backoffice.challenges.unit_drivers'), 'on' => $topNEnabled],
                                    ['key' => 'minAcceptanceRate', 'toggle' => 'minAcceptanceRateEnabled', 'label' => __('backoffice.challenges.crit_acceptance'), 'hint' => __('backoffice.challenges.crit_acceptance_hint'), 'prefix' => __('backoffice.challenges.at_least'), 'unit' => '%', 'on' => $minAcceptanceRateEnabled],
                                    ['key' => 'minRating', 'toggle' => 'minRatingEnabled', 'label' => __('backoffice.challenges.crit_rating'), 'hint' => __('backoffice.challenges.crit_rating_hint'), 'prefix' => __('backoffice.challenges.at_least'), 'unit' => '/ 5', 'on' => $minRatingEnabled],
                                    ['key' => 'minActiveDays', 'toggle' => 'minActiveDaysEnabled', 'label' => __('backoffice.challenges.crit_active_days'), 'hint' => __('backoffice.challenges.crit_active_days_hint'), 'prefix' => __('backoffice.challenges.at_least'), 'unit' => __('backoffice.challenges.unit_days'), 'on' => $minActiveDaysEnabled],
                                ] as $crit)
                                    @if ($crit['key'] !== 'topN' || $type === ChallengeType::Leaderboard->value)
                                        <div @class([
                                            'flex items-center gap-3 rounded border-2 p-3 transition-colors',
                                            'border-primary bg-primary-tint' => $crit['on'],
                                            'border-line bg-card' => ! $crit['on'],
                                        ])>
                                            <button type="button" wire:click="$toggle('{{ $crit['toggle'] }}')"
                                                    @class([
                                                        'flex size-[19px] shrink-0 items-center justify-center rounded border-2 text-xs font-bold text-white',
                                                        'border-primary bg-primary' => $crit['on'],
                                                        'border-input bg-line' => ! $crit['on'],
                                                    ])>{{ $crit['on'] ? '✓' : '' }}</button>

                                            <span class="min-w-0 flex-1">
                                                <b class="block text-[13.5px] text-ink">{{ $crit['label'] }}</b>
                                                <span class="block text-xs text-muted">{{ $crit['hint'] }}</span>
                                            </span>

                                            <span class="flex shrink-0 items-center gap-2 text-xs text-muted">
                                                {{ $crit['prefix'] }}
                                                <input wire:model.live="{{ $crit['key'] }}" type="text" @disabled(! $crit['on'])
                                                       class="w-[76px] rounded border border-input px-2.5 py-1.5 text-center text-[13.5px] font-semibold text-ink disabled:bg-surface disabled:text-muted">
                                                {{ $crit['unit'] }}
                                            </span>
                                        </div>
                                        @error($crit['key']) <p class="text-sm text-err-text">{{ $message }}</p> @enderror
                                    @endif
                                @endforeach
                            </div>
                            @endif
                        @endif

                        {{-- Étape 3 : période --}}
                        @if ($step === 3)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="wizard-start" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.period_start') }}</label>
                                    <input wire:model.live="periodStart" id="wizard-start" type="date"
                                           class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm focus:border-primary focus:outline-none">
                                    @error('periodStart') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="wizard-end" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.period_end') }}</label>
                                    <input wire:model.live="periodEnd" id="wizard-end" type="date"
                                           class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm focus:border-primary focus:outline-none">
                                    @error('periodEnd') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <p class="mt-4 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.recurrence') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($recurrences as $case)
                                    <button type="button" wire:click="$set('recurrence', '{{ $case->value }}')"
                                            @class([
                                                'rounded border-2 px-4 py-2 text-[13px] font-semibold transition-colors',
                                                'border-primary bg-primary-tint text-primary-text' => $recurrence === $case->value,
                                                'border-line bg-card text-ink hover:border-input' => $recurrence !== $case->value,
                                            ])>{{ $case->label() }}</button>
                                @endforeach
                            </div>

                            <div class="mt-4 rounded bg-zinc-100 p-4 text-[13px] leading-7 text-ink">
                                <p>{{ __('backoffice.challenges.period_recap_period') }} : <b>{{ \Illuminate\Support\Carbon::parse($periodStart)->translatedFormat('j M') }} → {{ \Illuminate\Support\Carbon::parse($periodEnd)->translatedFormat('j M') }}</b></p>
                                <p>{{ __('backoffice.challenges.period_recap_duration') }} : <b>{{ trans_choice('backoffice.challenges.days', \Illuminate\Support\Carbon::parse($periodStart)->diffInDays(\Illuminate\Support\Carbon::parse($periodEnd)) + 1, ['count' => \Illuminate\Support\Carbon::parse($periodStart)->diffInDays(\Illuminate\Support\Carbon::parse($periodEnd)) + 1]) }}</b></p>
                                <p>{{ __('backoffice.challenges.period_recap_closure') }} : <b>{{ $awardMode === AwardMode::SingleWinner->value || $type === ChallengeType::Surprise->value ? __('backoffice.challenges.closure_draw') : __('backoffice.challenges.closure_payout') }}</b></p>
                            </div>
                        @endif

                        {{-- Étape 4 : prix et attribution --}}
                        @if ($step === 4)
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
                                       class="mt-1.5 block w-full max-w-[260px] rounded border border-input px-3 py-2.5 text-sm focus:border-primary focus:outline-none">
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
                                                           class="mt-1.5 block w-[120px] rounded border border-input bg-card px-3 py-2 text-center text-[13.5px] font-bold text-ink focus:border-primary focus:outline-none">
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
                                       class="mt-1.5 block w-full max-w-[260px] rounded border border-input px-3 py-2.5 text-sm focus:border-primary focus:outline-none">
                                @error('populationMax') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                            @endif

                        @endif
                    </div>

                    {{-- Récapitulatif permanent : fond légèrement plus dense
                         et bord marqué, pour se distinguer du formulaire sans
                         basculer en sombre. --}}
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
                </div>

                <div class="flex items-center justify-between gap-4 border-t border-line bg-surface px-7 py-4">
                    @if ($step > 1)
                        <button type="button" wire:click="previousStep" class="rounded border border-input bg-card px-4 py-2.5 text-[13.5px] font-bold text-ink transition-colors hover:bg-line">
                            {{ __('backoffice.challenges.previous') }}
                        </button>
                    @else
                        <span></span>
                    @endif

                    {{-- Le refus de doublon est rattaché à `name`, saisi à
                         l'étape 2 : sans ce rappel, l'erreur serait invisible
                         depuis la dernière étape. --}}
                    @error('name')
                        <p class="flex-1 text-[13px] font-semibold text-err-text">{{ $message }}</p>
                    @else
                        <p class="flex-1 text-[13px] text-muted">{{ $this->stepHint() }}</p>
                    @enderror

                    @if ($step < \App\Livewire\Challenges\Wizard::LAST_STEP)
                        <button type="button" wire:click="nextStep" wire:loading.attr="disabled"
                                class="flex shrink-0 items-center gap-2.5 rounded bg-ink px-5 py-3 text-[13.5px] font-bold text-white transition-colors hover:bg-sidebar-line disabled:opacity-60">
                            {{ __('backoffice.challenges.continue') }} <span class="font-extrabold">→</span>
                        </button>
                    @else
                        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                                class="flex shrink-0 items-center gap-2.5 rounded bg-primary px-5 py-3 text-[13.5px] font-bold text-white transition-colors hover:bg-primary-hover disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">
                                {{ auth()->user()?->hasRole('direction') ? __('backoffice.challenges.create_and_schedule') : __('backoffice.challenges.submit_to_direction') }}
                            </span>
                            <span wire:loading wire:target="save">{{ __('backoffice.challenges.saving') }}</span>
                            <span class="font-extrabold">→</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
