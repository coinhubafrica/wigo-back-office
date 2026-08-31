@php
    use App\Enums\ChallengeType;
@endphp
{{--
    Étape 1 : type de challenge.

    Rendue par `wizard.blade.php`, qui garde l'état : ce fichier ne fait que
    la mise en forme de l'étape et lit les propriétés du composant Wizard
    (`$type`, `$isTicketBased`, `$minOrders`, `$types`).
--}}

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
                    {{-- Case à cocher simulée : `aria-pressed` porte
                         l'état, que seul le glyphe indiquait. --}}
                    <button type="button" wire:click="$toggle('isTicketBased')"
                            aria-pressed="{{ $isTicketBased ? 'true' : 'false' }}"
                            class="flex w-full items-start gap-2.5 text-left">
                        <span @class([
                            'mt-0.5 flex size-[19px] shrink-0 items-center justify-center rounded border-2 text-white',
                            'border-primary bg-primary' => $isTicketBased,
                            'border-input bg-card' => ! $isTicketBased,
                        ])>
                            @if ($isTicketBased)
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            @endif
                        </span>
                        <span>
                            <b class="block text-[13.5px] text-ink">{{ __('backoffice.challenges.is_ticket_based') }}</b>
                            <span class="block text-xs leading-relaxed text-muted">{{ __('backoffice.challenges.is_ticket_based_hint') }}</span>
                        </span>
                    </button>

                    @if ($isTicketBased)
                        <div class="mt-3.5 flex flex-wrap items-center gap-2.5 border-t border-line pt-3.5 text-[13px] text-muted">
                            <span>{{ __('backoffice.challenges.one_ticket_per') }}</span>
                            <input wire:model.live="minOrders" type="number" min="1"
                                   class="w-[92px] rounded border border-input bg-card px-2.5 py-1.5 text-center text-[13.5px] font-bold text-ink focus:border-primary">
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

