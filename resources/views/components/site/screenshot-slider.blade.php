@props([
    /**
     * Captures à faire défiler.
     *
     * @var list<array{screenshot: string, alt: string, title: string, body: string}>
     */
    'items',
])

{{--
    Rangée défilante des captures de l'application.

    Le défilement est porté par le CSS (`overflow-x-auto` + `snap-x`) et non par
    Alpine : au doigt, à la molette et au clavier la rangée fonctionne même si
    le bundle Livewire ne part pas — le piège documenté dans `.ai/rules/site.md`.
    `siteSlider` n'ajoute que les flèches et la position courante, et le gabarit
    les masque tant qu'Alpine n'a pas démarré (`x-cloak`).

    La barre native est masquée (`[scrollbar-width:none]`) : les points et les
    flèches disent déjà où l'on est, et la barre coupait le bas des cadres.

    Le lissage est posé par `siteSlider` le temps du saut, et la classe
    `scroll-smooth` retirée : avec `scroll-behavior: smooth` figé en CSS, un
    défilement programmé n'était jamais validé et les flèches comme les points
    restaient sans effet (vérifié dans Chrome — `scrollLeft` retombait à 0).

    Les flèches sont posées en absolu de part et d'autre du ruban, centrées sur
    la hauteur des cadres (la légende est exclue du calcul, sinon elles
    descendaient sous les captures). Sous `sm` elles sortiraient de l'écran :
    elles y sont masquées, le doigt et les points suffisent.
--}}
<div x-data="siteSlider" class="relative [--site-shot-h:calc(1rem+(min(80vw,300px)-2rem)*982/480)]">
    <ul x-ref="track"
        class="flex snap-x snap-mandatory gap-5 overflow-x-auto pb-2 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($items as $item)
            {{-- Chaque capture reprend la carte du site d'origine (fond blanc,
                 filet, rayon 16 px, ombre et relèvement au survol) : c'est le
                 même habillage que `x-site.feature-card`, la rangée ne crée
                 donc pas un second langage visuel. --}}
            <li class="flex w-[min(80vw,300px)] flex-none flex-col snap-start rounded-site bg-card p-4 shadow-lift transition-transform duration-200 hover:-translate-y-1">
                <figure class="overflow-hidden rounded-[12px] border border-line bg-site-surface">
                    <picture>
                        <source type="image/webp"
                                srcset="{{ Vite::asset("resources/images/site/captures/{$item['screenshot']}.webp") }}">
                        <img src="{{ Vite::asset("resources/images/site/captures/{$item['screenshot']}.jpg") }}"
                             alt="{{ $item['alt'] }}"
                             width="480" height="982"
                             loading="lazy" decoding="async"
                             class="block h-auto w-full">
                    </picture>
                </figure>
                <h3 class="mt-3.5 text-[17.5px] font-bold text-ink">{{ $item['title'] }}</h3>
                <p class="mt-2 text-[14.5px] text-site-muted">{{ $item['body'] }}</p>
            </li>
        @endforeach
    </ul>

    {{-- Commandes : décoratives tant que la rangée défile toute seule, d'où
         `x-cloak` plutôt qu'un rendu serveur qui ne ferait rien sans Alpine. --}}
    <div x-cloak>
        {{-- Les flèches sont centrées sur la hauteur du cadre, calculée du
             rapport 480 × 982 de la capture : ancrées sur la hauteur de
             l'enveloppe, elles descendaient sous les captures (la légende, la
             description et les points comptent dans celle-ci). Le `1rem` est
             le rembourrage haut de la carte, au-dessus du cadre. --}}
        <button type="button"
                x-on:click="prev()"
                x-bind:disabled="atStart"
                class="absolute -left-4 top-[calc((var(--site-shot-h))/2)] hidden size-10 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-card text-ink shadow-lift transition hover:bg-site-surface disabled:pointer-events-none disabled:opacity-0 sm:flex lg:-left-6"
                aria-label="Capture précédente">
            <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10 3 5 8l5 5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <button type="button"
                x-on:click="next()"
                x-bind:disabled="atEnd"
                class="absolute -right-4 top-[calc((var(--site-shot-h))/2)] hidden size-10 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-card text-ink shadow-lift transition hover:bg-site-surface disabled:pointer-events-none disabled:opacity-0 sm:flex lg:-right-6"
                aria-label="Capture suivante">
            <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="m6 3 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <ul class="mt-5 flex items-center justify-center gap-1.5">
            @foreach ($items as $index => $item)
                <li>
                    <button type="button"
                            x-on:click="go({{ $index }})"
                            x-bind:class="index === {{ $index }} ? 'w-5 bg-primary' : 'w-2 bg-line'"
                            class="h-2 rounded-full transition-all"
                            aria-label="Aller à la capture {{ $index + 1 }} : {{ $item['title'] }}"></button>
                </li>
            @endforeach
        </ul>
    </div>
</div>
