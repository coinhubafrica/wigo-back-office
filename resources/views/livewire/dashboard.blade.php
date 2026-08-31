<div>
    {{-- Chaque carte renvoie vers le module qui porte le détail : un chiffre du
         tableau de bord est un point d'entrée, pas une impasse. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ route($card['route']) }}" wire:navigate
               class="group rounded border border-line bg-card p-5 transition-colors hover:border-primary">
                <p class="text-sm text-muted">{{ $card['label'] }}</p>
                {{-- La teinte d'alerte ne s'applique qu'à un compteur non nul :
                     un « 0 » en rouge se lirait comme un incident. --}}
                <p class="mt-1 text-2xl font-semibold {{ $card['value'] > 0 ? $card['tone'] : 'text-ink' }}">
                    {{ number_format($card['value'], 0, ',', ' ') }}
                </p>
                <span class="mt-2 flex items-center gap-1 text-xs font-semibold text-muted group-hover:text-primary-text">
                    {{ __('backoffice.dashboard.see_module') }}
                    <svg class="size-3.5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </span>
            </a>
        @endforeach
    </div>

    <p class="mt-6 text-sm text-muted">
        {{ __('backoffice.dashboard.pending_modules_notice') }}
    </p>
</div>
