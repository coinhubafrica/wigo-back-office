@php
    /**
     * Onglets du module Challenges. Les lots physiques ne sont qu'une facette
     * du même module : les afficher comme un onglet évite de les faire passer
     * pour une destination séparée.
     */
    $tabs = [
        ['label' => __('backoffice.challenges.tab_challenges'), 'route' => \App\Enums\BackOfficeModule::Challenges->route(), 'active' => $active === 'challenges'],
        ['label' => __('backoffice.challenges.tab_prizes'), 'route' => 'bo.challenges.prizes', 'active' => $active === 'prizes'],
    ];
@endphp

<nav class="flex items-center gap-1 border-b border-line" aria-label="{{ __('backoffice.challenges.tab_challenges') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" wire:navigate
           @if ($tab['active']) aria-current="page" @endif
           @class([
               '-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors',
               'border-primary text-primary-text' => $tab['active'],
               'border-transparent text-muted hover:border-line hover:text-ink' => ! $tab['active'],
           ])>{{ $tab['label'] }}</a>
    @endforeach
</nav>
