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
        {{-- Même `modalFocus` que `<x-modal>` : l'assistant garde sa propre
             coquille pour le bandeau d'étapes, pas son propre piège de
             tabulation. --}}
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/70 p-6 backdrop-blur-sm"
             wire:key="wizard-modal"
             x-data="modalFocus"
             x-on:keydown.escape.window="$wire.close()"
             x-on:keydown.tab="trap($event)"
             x-on:click="$wire.close()">
            <div class="w-full max-w-4xl overflow-hidden rounded-lg bg-card shadow-[0_24px_64px_-12px_rgba(9,9,11,0.45)] ring-1 ring-ink/10"
                 x-ref="panel"
                 x-on:click.stop
                 role="dialog" aria-modal="true" aria-labelledby="wizard-title">
                {{-- Bandeau d'en-tête teinté : détache le titre du corps du
                     formulaire et ancre l'étape en cours. --}}
                <div class="flex items-start justify-between gap-4 border-b border-line bg-surface px-7 py-5">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-primary-text">
                            {{ __('backoffice.challenges.wizard_eyebrow', ['step' => $step, 'total' => \App\Livewire\Challenges\Wizard::LAST_STEP]) }}
                        </p>
                        <h2 id="wizard-title" class="mt-1.5 text-2xl font-bold tracking-tight text-ink">{{ $this->stepTitle() }}</h2>
                    </div>
                    <button type="button" wire:click="close"
                            class="flex size-8 shrink-0 items-center justify-center rounded border border-line bg-card text-muted transition-colors hover:border-input hover:text-ink"
                            aria-label="{{ __('backoffice.challenges.cancel') }}">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
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
                            ])>
                                @if ($isDone)
                                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
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

                {{-- Corps de l'assistant : chaque étape vit dans son propre
                     fichier sous `wizard/`. L'état reste porté par le
                     composant, les partiels ne font que la mise en forme. --}}
                <div class="grid items-stretch gap-0 lg:grid-cols-[1fr_300px]">
                    <div class="min-h-[380px] px-7 py-5">
                        @switch ($step)
                            @case (1)
                                @include('livewire.challenges.wizard.step-type')
                                @break
                            @case (2)
                                @include('livewire.challenges.wizard.step-criteria')
                                @break
                            @case (3)
                                @include('livewire.challenges.wizard.step-period')
                                @break
                            @case (4)
                                @include('livewire.challenges.wizard.step-prize')
                                @break
                        @endswitch
                    </div>

                    @include('livewire.challenges.wizard.recap')
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
                            {{ __('backoffice.challenges.continue') }} <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    @else
                        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                                class="flex shrink-0 items-center gap-2.5 rounded bg-primary px-5 py-3 text-[13.5px] font-bold text-white transition-colors hover:bg-primary-hover disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">
                                {{ auth()->user()?->hasRole('direction') ? __('backoffice.challenges.create_and_schedule') : __('backoffice.challenges.submit_to_direction') }}
                            </span>
                            <span wire:loading wire:target="save">{{ __('backoffice.challenges.saving') }}</span>
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
