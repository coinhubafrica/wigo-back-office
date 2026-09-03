@php
    use App\Enums\AwardMode;
    use App\Enums\ChallengeType;
@endphp
{{--
    Étape 3 : période et récurrence.

    Lit `$periodStart`, `$periodEnd`, `$recurrence`, `$recurrences`,
    `$awardMode` et `$type`.
--}}

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="wizard-start" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.period_start') }}</label>
        <input wire:model.live="periodStart" id="wizard-start" type="date"
               class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm focus:border-primary">
        @error('periodStart') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="wizard-end" class="block text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{{ __('backoffice.challenges.period_end') }}</label>
        <input wire:model.live="periodEnd" id="wizard-end" type="date"
               class="mt-1.5 block w-full rounded border border-input px-3 py-2.5 text-sm focus:border-primary">
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

<div class="mt-4 rounded bg-neutral-bg p-4 text-[13px] leading-7 text-ink">
    <p>{{ __('backoffice.challenges.period_recap_period') }} : <b>{{ \Illuminate\Support\Carbon::parse($periodStart)->translatedFormat('j M') }} → {{ \Illuminate\Support\Carbon::parse($periodEnd)->translatedFormat('j M') }}</b></p>
    <p>{{ __('backoffice.challenges.period_recap_duration') }} : <b>{{ trans_choice('backoffice.challenges.days', \Illuminate\Support\Carbon::parse($periodStart)->diffInDays(\Illuminate\Support\Carbon::parse($periodEnd)) + 1, ['count' => \Illuminate\Support\Carbon::parse($periodStart)->diffInDays(\Illuminate\Support\Carbon::parse($periodEnd)) + 1]) }}</b></p>
    <p>{{ __('backoffice.challenges.period_recap_closure') }} : <b>{{ $awardMode === AwardMode::SingleWinner->value || $type === ChallengeType::Surprise->value ? __('backoffice.challenges.closure_draw') : __('backoffice.challenges.closure_payout') }}</b></p>
</div>
