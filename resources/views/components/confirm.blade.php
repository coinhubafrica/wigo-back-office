@props([
    /** Méthode Livewire qui referme sans agir. */
    'close',
    /** Méthode Livewire exécutée à la confirmation ; sert aussi de cible d'attente. */
    'action',
    'title',
    'body' => null,
    'confirmLabel',
    /** `danger` pour une action destructrice : bouton rouge et pictogramme d'alerte. */
    'variant' => 'primary',
    /** Libellé affiché pendant l'aller-retour. */
    'loading' => null,
])

{{--
    Dialogue de confirmation. Dix copies quasi identiques vivaient dans les
    modules, et aucune ne gardait son bouton pendant l'action : un double clic
    sur « Relancer » relançait deux fois. Ici la garde est posée d'office.

    La confirmation est un état du composant (`$confirmingId`), jamais
    `wire:confirm` : le dialogue natif bloque les tests pilotés par navigateur.
--}}
<x-modal :close="$close" size="sm" :label="$title" {{ $attributes }}>
    <div class="flex items-start gap-3.5">
        @if ($variant === 'danger')
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-err-bg text-err-text" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink">{{ $title }}</p>
            @if ($body)
                <p class="mt-1.5 text-sm text-muted">{{ $body }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>

    <x-slot:footer>
        <x-button variant="secondary" wire:click="{{ $close }}">{{ __('backoffice.common.cancel') }}</x-button>
        <x-button :variant="$variant" wire:click="{{ $action }}" :target="$action">
            {{ $confirmLabel }}
            <x-slot:loading>{{ $loading ?? __('backoffice.common.working') }}</x-slot:loading>
        </x-button>
    </x-slot:footer>
</x-modal>
