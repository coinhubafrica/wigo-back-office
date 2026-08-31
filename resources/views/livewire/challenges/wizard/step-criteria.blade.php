@php
    use App\Enums\ChallengeType;
@endphp
{{--
    Étape 2 : nom et critères d'éligibilité.

    Lit `$name`, `$type` et les couples `$<critère>` / `$<critère>Enabled` du
    composant Wizard, plus `$this->isTicketBasedRaffle()`.
--}}

<label for="wizard-name" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">
    {{ __('backoffice.challenges.field_name') }}
</label>
<input wire:model="name" id="wizard-name" type="text" placeholder="{{ $this->suggestedName() }}"
       class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm placeholder:text-muted focus:border-primary">
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
                {{-- Case à cocher simulée : sans `aria-pressed` ni
                     intitulé, ce bouton de 19 px était annoncé
                     vide et sans état. --}}
                <button type="button" wire:click="$toggle('{{ $crit['toggle'] }}')"
                        aria-pressed="{{ $crit['on'] ? 'true' : 'false' }}"
                        aria-label="{{ $crit['label'] }}"
                        @class([
                            'flex size-[19px] shrink-0 items-center justify-center rounded border-2 text-white',
                            'border-primary bg-primary' => $crit['on'],
                            'border-input bg-line' => ! $crit['on'],
                        ])>
                    @if ($crit['on'])
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    @endif
                </button>

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
