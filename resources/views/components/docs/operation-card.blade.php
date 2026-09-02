@props(['entry', 'href'])

{{--
    La carte d'une opération dans l'index d'un tag : de quoi décider si c'est
    la bonne page, pas le contrat entier.
--}}
<li>
    <a href="{{ $href }}"
       class="block rounded border border-line bg-card p-3 hover:border-primary">
        <span class="flex flex-wrap items-center gap-2">
            <x-docs.method :method="$entry['method']" />
            <code class="font-mono text-[13px] font-semibold">{{ $entry['path'] }}</code>
            @if ($entry['public'])
                <span class="rounded bg-neutral-bg px-1.5 py-0.5 text-[11px] font-semibold text-neutral-text">
                    public
                </span>
            @endif
        </span>

        @if ($entry['summary'] !== null)
            <span class="mt-1.5 block text-sm text-ink">{{ $entry['summary'] }}</span>
        @endif
    </a>
</li>
