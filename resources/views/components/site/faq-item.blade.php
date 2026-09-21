@props([
    /** Question affichée dans l'en-tête dépliable. */
    'question',
])

{{--
    Une question fréquente, en `<details>` natif : le dépliage ne dépend
    d'aucun script, se lit sans JavaScript et reste ouvrable au clavier —
    contrairement à un accordéon Alpine, qui laisserait la réponse
    inaccessible si le bundle Livewire ne partait pas (cf. `.ai/rules/site.md`).

    Le marqueur natif est masqué (`[&::-webkit-details-marker]:hidden` et
    `list-none`) au profit du disque « + / − » à droite, qui bascule par
    `group-open`.
--}}
<details {{ $attributes->class(['group overflow-hidden rounded-[14px] border border-line bg-card transition-shadow open:border-site-green open:shadow-[0_8px_24px_rgb(0_104_55_/_0.10)]']) }}>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3.5 px-5 py-4 text-[15.5px] font-bold text-ink [&::-webkit-details-marker]:hidden">
        {{ $question }}
        <span class="flex size-[26px] flex-none items-center justify-center rounded-full bg-site-green/12 text-[17px] font-extrabold text-site-green transition-transform duration-200 group-open:rotate-180"
              aria-hidden="true">
            <span class="group-open:hidden">+</span>
            <span class="hidden group-open:inline">−</span>
        </span>
    </summary>
    <div class="px-5 pb-[18px] text-[14.5px] text-site-muted [&_a]:font-semibold [&_a]:text-site-green">
        {{ $slot }}
    </div>
</details>
