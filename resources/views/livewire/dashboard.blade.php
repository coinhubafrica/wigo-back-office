<div>
    @if ($cards === [])
        <x-empty-state tone="neutral" size="lg"
                       :title="__('backoffice.dashboard.no_cards')"
                       :hint="__('backoffice.dashboard.no_cards_hint')" />
    @else
        {{-- Chaque carte renvoie vers le module qui porte le détail : un chiffre du
             tableau de bord est un point d'entrée, pas une impasse. La teinte
             d'alerte ne s'applique qu'à un compteur non nul : un « 0 » en rouge
             se lirait comme un incident. --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <x-kpi-card :label="$card['label']"
                            :value="number_format($card['value'], 0, ',', ' ')"
                            :alert="$card['alert'] && $card['value'] > 0"
                            :href="route($card['route'])"
                            :hint="__('backoffice.dashboard.see_module')"
                            tone="primary">
                    <x-slot:icon>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $card['icon'] }}"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-sm text-muted">
        {{ __('backoffice.dashboard.pending_modules_notice') }}
    </p>
</div>
