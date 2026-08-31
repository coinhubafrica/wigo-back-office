@props([
    /** Action Livewire appelée pour fermer (fond, Échap, bouton ×). */
    'close',
    /** Titre affiché dans l'en-tête ; sert aussi de nom accessible. */
    'title' => null,
    /**
     * Nom accessible quand la modale n'a pas d'en-tête (dialogues de
     * confirmation) : sans lui, elle serait annoncée sans intitulé.
     */
    'label' => null,
    /** Largeur maximale du panneau. */
    'maxWidth' => 'max-w-lg',
    /** Aligne le panneau en haut (formulaires longs) plutôt qu'au centre. */
    'align' => 'center',
])

@php
    /**
     * Identifiant stable pour relier le panneau à son titre via
     * `aria-labelledby` : sans nom accessible, la modale est annoncée comme
     * un simple groupe.
     */
    $titleId = 'modal-title-'.\Illuminate\Support\Str::random(8);
@endphp

{{--
    Coquille de modale partagée.

    Elle porte ce que les copies dispersées dans les modules n'avaient pas :
    `role="dialog"`, un nom accessible, le piège de tabulation, la fermeture
    par Échap et le retour du focus sur l'élément déclencheur. Le contenu
    reste propre à chaque module et arrive par le slot.
--}}
{{-- `modalFocus` : piège de tabulation, focus initial et retour du focus.
     Défini dans `resources/js/app.js` pour que l'assistant Challenges, qui a
     sa propre coquille, partage exactement le même comportement. --}}
<div
    x-data="modalFocus"
    x-on:keydown.escape.window="$wire.{{ $close }}()"
    x-on:keydown.tab="trap($event)"
    wire:click="{{ $close }}"
    @class([
        'fixed inset-0 z-50 flex justify-center bg-ink/45 p-6',
        'items-center' => $align === 'center',
        'items-start overflow-y-auto py-10' => $align !== 'center',
    ])>
    <div
        x-ref="panel"
        wire:click.stop
        role="dialog"
        aria-modal="true"
        @if ($title) aria-labelledby="{{ $titleId }}" @elseif ($label) aria-label="{{ $label }}" @endif
        {{ $attributes->class(['w-full overflow-hidden rounded bg-card shadow-xl', $maxWidth]) }}>
        @if ($title)
            <div class="flex items-center gap-3 border-b border-line px-5 py-4">
                <p id="{{ $titleId }}" class="text-sm font-semibold text-ink">{{ $title }}</p>
                <span class="flex-1"></span>
                <button type="button" wire:click="{{ $close }}"
                        aria-label="{{ __('backoffice.announcements.cancel') }}"
                        class="flex size-8 items-center justify-center rounded text-muted hover:bg-surface hover:text-ink">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        @endif

        {{ $slot }}
    </div>
</div>
