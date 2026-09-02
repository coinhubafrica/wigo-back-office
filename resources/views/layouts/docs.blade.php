@php
    use App\Support\Docs\DocsMarkdown;

    /*
     * Gabarit de la documentation. Les pages le remplissent par héritage ;
     * $title, $guides, $current, $currentTag et $sections viennent du
     * contrôleur ou de la vue fille.
     */
    $guides ??= [];
    $current ??= null;
    $currentTag ??= null;
    $currentOperation ??= null;
    $sections ??= [];
    $reference ??= null;

    // Le jeton de consultation suit chaque lien interne via
    // `DocsMarkdown::url()` : un helper partagé plutôt qu'une fermeture, car
    // les vues filles remplissent `@section` hors de la portée de ce bloc.
    $tags = $reference?->tags() ?? [];

    // Calme, comme la référence : le fond ne marque plus l'état actif, un
    // texte plus clair et plus épais suffit — la barre latérale reste lisible
    // d'un regard plutôt que ponctuée de blocs colorés.
    $linkClasses = 'block rounded-md px-2.5 py-1.5 text-zinc-400 hover:text-white'
        .' aria-[current]:font-semibold aria-[current]:text-white';

    // Le conteneur de page : plus large sur les pages qui en profitent
    // (référence à deux colonnes), sans imposer la même largeur partout.
    $containerClass = $containerClass ?? 'max-w-4xl';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — WiGO PRO</title>
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-surface font-sans text-ink antialiased">
<div class="flex min-h-full">
    <aside class="fixed inset-y-0 left-0 z-20 flex w-[260px] flex-col bg-sidebar">
        <div class="shrink-0 border-b border-sidebar-line px-5 py-4">
            <a href="{{ DocsMarkdown::url(route('docs.reference')) }}">
                <img src="{{ Vite::asset('resources/images/logo-wigo-pro-white.png') }}"
                     alt="WiGO PRO" class="w-[130px]">
            </a>
            <p class="mt-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-400">
                API mobile
            </p>
        </div>

        <nav aria-label="Documentation" class="flex-1 space-y-6 overflow-y-auto px-3 py-5 text-sm">
            {{-- Guides d'abord : on lit le contrat temps réel avant de
                 parcourir trente-quatre endpoints. Chaque groupe est une
                 section visuellement fermée (titre + liste), séparée de la
                 suivante par un filet plutôt que par le seul espacement. --}}
            <div>
                <h2 class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
                    Guides
                </h2>
                <ul class="space-y-0.5">
                    @foreach ($guides as $item)
                        <li>
                            <a href="{{ DocsMarkdown::url(route('docs.guide', ['slug' => $item->slug])) }}"
                               @if ($current === $item->slug) aria-current="page" @endif
                               class="{{ $linkClasses }}">
                                {{ $item->title }}
                            </a>

                            {{-- Le sommaire du guide courant, sur un rail
                                 vertical qui rattache visuellement les
                                 sous-titres à leur page. --}}
                            @if ($current === $item->slug && $sections !== [])
                                <ul class="mt-0.5 ml-4 space-y-0.5 border-l border-sidebar-line pl-2.5">
                                    @foreach ($sections as $section)
                                        <li>
                                            <a href="{{ DocsMarkdown::url(url()->current().'#'.$section['id']) }}"
                                               class="block truncate rounded px-2 py-1 text-[12.5px] text-zinc-500 hover:text-white
                                                      @if (($section['level'] ?? 2) === 3) pl-3 @endif">
                                                {{ $section['text'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="border-t border-sidebar-line pt-5">
                <h2 class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
                    Contrat REST
                </h2>
                <ul class="space-y-0.5">
                    <li>
                        <a href="{{ DocsMarkdown::url(route('docs.reference')) }}"
                           @if ($current === 'reference' && $currentTag === null) aria-current="page" @endif
                           class="{{ $linkClasses }}">
                            Vue d'ensemble
                        </a>
                    </li>

                    @foreach ($tags as $tag)
                        @php($isOpen = $tag['slug'] === $currentTag)
                        <li>
                            {{-- Le chevron marque le groupe comme dépliable,
                                 discret plutôt que dominant — c'est le seul
                                 signe d'un mécanisme, pas un bloc de couleur.
                                 Rendu côté serveur — l'état ouvert est une
                                 fonction de l'URL — donc pivoté directement
                                 dans le bon sens plutôt qu'animé au clic. --}}
                            <a href="{{ DocsMarkdown::url(route('docs.tag', ['tag' => $tag['slug']])) }}"
                               @if ($isOpen && $currentOperation === null) aria-current="page"
                               @elseif ($isOpen) aria-current="true" @endif
                               aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                               class="{{ $linkClasses }} flex items-center justify-between gap-2">
                                <span class="truncate">{{ $tag['name'] }}</span>
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"
                                     class="size-3 shrink-0 text-zinc-600 transition-transform duration-150
                                            @if ($isOpen) rotate-90 @endif">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                          d="M7.05 4.05a.75.75 0 0 1 1.06 0l5 5a.75.75 0 0 1 0 1.06l-5 5a.75.75 0 1 1-1.06-1.06L11.44 10 7.05 5.61a.75.75 0 0 1 0-1.06Z" />
                                </svg>
                            </a>

                            {{-- Dépliage rendu côté serveur : déterministe
                                 pour une URL donnée, donc fiable à tester. Un
                                 seul filet vertical rattache le groupe à ses
                                 opérations — pas de bloc de fond, la liste
                                 reste au même niveau visuel que le reste. --}}
                            @if ($isOpen)
                                <ul class="ml-2.5 space-y-0.5 border-l border-sidebar-line py-1 pl-3">
                                    @foreach ($reference->operations($tag['slug']) as $entry)
                                        <li>
                                            <a href="{{ DocsMarkdown::url(route('docs.operation', ['tag' => $entry['tagSlug'], 'operation' => $entry['id']])) }}"
                                               @if ($entry['id'] === $currentOperation) aria-current="page" @endif
                                               class="flex items-center justify-between gap-2 rounded py-1 pr-1.5 text-zinc-500
                                                      hover:text-white aria-[current]:font-semibold aria-[current]:text-white">
                                                <span class="truncate font-mono text-[12px]">{{ $entry['path'] }}</span>
                                                <x-docs.method :method="$entry['method']" plain class="shrink-0" />
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="border-t border-sidebar-line pt-5">
                <h2 class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
                    Fichier
                </h2>
                <ul>
                    <li>
                        <a href="{{ DocsMarkdown::url(route('docs.spec')) }}"
                           class="flex items-center gap-2 rounded-md px-2.5 py-1.5 font-mono text-[13px] text-zinc-300
                                  hover:bg-sidebar-line hover:text-white">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="size-3.5 shrink-0 text-zinc-500">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                      d="M4 2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7.914a2 2 0 0 0-.586-1.414l-3.914-3.914A2 2 0 0 0 8.086 2H4Zm4 1.5v2A1.5 1.5 0 0 0 9.5 7h2L8 3.5Z" />
                            </svg>
                            openapi.json
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </aside>

    <main class="ml-[260px] min-w-0 flex-1 px-8 py-8">
        <div class="mx-auto {{ $containerClass }}">
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
