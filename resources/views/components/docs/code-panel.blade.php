@props(['title', 'code', 'language' => 'bash'])

{{--
    Panneau de code sombre à barre de titre, pour les exemples de requête et
    de réponse d'une opération. Réutilisé tel quel dans les deux, plutôt qu'un
    `<pre>` nu par appelant. Coloré côté serveur par `DocsHighlighter` — le
    thème `hljs-*` vit dans `.docs-hljs` de `resources/css/app.css`.
--}}
<div {{ $attributes->class(['overflow-hidden rounded border border-sidebar-line bg-sidebar']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-sidebar-line px-3 py-2">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ $title }}</span>
        @isset($badge)
            {{ $badge }}
        @endisset
    </div>
    <pre class="docs-hljs overflow-x-auto px-3 py-2.5 font-mono text-[12px] leading-relaxed">{!! $language === 'bash' ? App\Support\Docs\DocsHighlighter::highlightCurl($code) : App\Support\Docs\DocsHighlighter::highlight($code, $language) !!}</pre>
</div>
