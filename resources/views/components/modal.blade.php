@props([
    /** Nom de la méthode Livewire appelée pour fermer (fond, Échap, bouton ×). Un nom, jamais une expression : le composant écrit `$wire.{close}()`. */
    'close',
    /** Titre affiché dans l'en-tête ; sert aussi de nom accessible. */
    'title' => null,
    /** Ligne d'aide sous le titre. */
    'description' => null,
    /**
     * Nom accessible quand la modale n'a pas d'en-tête (dialogues de
     * confirmation) : sans lui, elle serait annoncée sans intitulé.
     */
    'label' => null,
    /** Largeur : `sm` (confirmation), `md` (formulaire court), `lg`, `xl`. */
    'size' => 'md',
    /** Ancienne API : classe `max-w-*` explicite. Préférer `size`. */
    'maxWidth' => null,
    /** Aligne le panneau en haut (formulaires longs) plutôt qu'au centre. */
    'align' => 'center',
    /** Sans marge intérieure : le contenu gère lui-même son cadre (liste, tableau). */
    'flush' => false,
])

@php
    /**
     * Identifiant stable pour relier le panneau à son titre via
     * `aria-labelledby` : sans nom accessible, la modale est annoncée comme
     * un simple groupe.
     */
    $titleId = 'modal-title-'.\Illuminate\Support\Str::random(8);

    $width = $maxWidth ?? match ($size) {
        'sm' => 'max-w-sm',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
        default => 'max-w-lg',
    };
@endphp

{{--
    Coquille de modale partagée.

    Elle porte ce que les copies dispersées dans les modules n'avaient pas :
    `role="dialog"`, un nom accessible, le piège de tabulation, la fermeture
    par Échap et le retour du focus sur l'élément déclencheur. Le contenu
    reste propre à chaque module et arrive par le slot ; les boutons par le
    slot `footer`, pour que la barre d'actions soit la même partout.

    Le panneau est borné à la hauteur de la fenêtre et son corps défile seul :
    l'en-tête et les actions restent visibles sur un long formulaire.
--}}
{{-- `modalFocus` : piège de tabulation, focus initial et retour du focus.
     Défini dans `resources/js/app.js` pour que l'assistant Challenges, qui a
     sa propre coquille, partage exactement le même comportement. --}}
<div
    x-data="modalFocus"
    x-show="show"
    x-transition.opacity.duration.150ms
    x-on:keydown.escape.window="$wire.{{ $close }}()"
    x-on:keydown.tab="trap($event)"
    wire:click="{{ $close }}"
    @class([
        'fixed inset-0 z-50 flex justify-center bg-ink/45 p-4 sm:p-6',
        'items-center' => $align === 'center',
        'items-start overflow-y-auto sm:py-10' => $align !== 'center',
    ])>
    <div
        x-ref="panel"
        x-show="show"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="scale-[0.97] opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        wire:click.stop
        role="dialog"
        aria-modal="true"
        @if ($title) aria-labelledby="{{ $titleId }}" @elseif ($label) aria-label="{{ $label }}" @endif
        {{ $attributes->class(['flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded bg-card shadow-xl', $width]) }}>
        @if ($title)
            <div class="flex shrink-0 items-start gap-3 border-b border-line px-5 py-4">
                <div class="min-w-0 flex-1">
                    <h2 id="{{ $titleId }}" class="text-sm font-semibold text-ink">{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-0.5 text-xs text-muted">{{ $description }}</p>
                    @endif
                </div>
                <x-button icon size="sm" variant="secondary" class="-mr-1 -mt-1 border-0"
                          wire:click="{{ $close }}" aria-label="{{ __('backoffice.common.close') }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </x-button>
            </div>
        @endif

        <div @class(['min-h-0 flex-1 overflow-y-auto', 'px-5 py-4' => ! $flush])>
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2.5 border-t border-line bg-surface px-5 py-3.5">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
