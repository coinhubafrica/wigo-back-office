@extends('layouts.docs', ['current' => 'reference'])

@php
    /*
     * Vue d'ensemble : ce qu'il faut savoir avant de parcourir les opérations.
     * Le détail vit sur la page de chaque tag, puis de chaque opération.
     */
    $title = $spec['info']['title'] ?? 'API';
    $tags = $reference->tags();
@endphp

@section('content')
    <header class="mb-6 border-b border-line pb-4">
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-muted">
            <span>Version {{ $spec['info']['version'] ?? '—' }}</span>
            <span>OpenAPI {{ $spec['openapi'] ?? '—' }}</span>
            @if ($reference->serverUrl() !== '')
                <code class="font-mono text-[13px]">{{ $reference->serverUrl() }}</code>
            @endif
        </p>
    </header>

    @if (isset($spec['info']['description']))
        <div class="docs-prose mb-8">
            {!! App\Support\Docs\DocsMarkdown::toHtml($spec['info']['description']) !!}
        </div>
    @endif

    <section class="mb-8 rounded border border-line bg-card p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Authentification</h2>
        <p class="mt-1.5 text-sm">
            Jeton porteur (<code class="font-mono text-[13px]">Authorization: Bearer &lt;token&gt;</code>),
            obtenu via <code class="font-mono text-[13px]">POST /auth/otp/verify</code>. Les opérations
            sans mention contraire l'exigent.
        </p>
    </section>

    <section>
        <h2 class="text-lg font-semibold">Contrat REST</h2>
        <p class="mt-1 text-sm text-muted">
            {{ collect($tags)->sum('count') }} opérations, regroupées par module.
        </p>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($tags as $tag)
                <li>
                    <a href="{{ App\Support\Docs\DocsMarkdown::url(route('docs.tag', ['tag' => $tag['slug']])) }}"
                       class="flex h-full items-start justify-between gap-4 rounded border border-line bg-card p-3
                              hover:border-primary">
                        <span>
                            <span class="font-semibold">{{ $tag['name'] }}</span>
                            @if ($tag['description'] !== null)
                                <span class="mt-1 block text-sm text-muted">{{ $tag['description'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 rounded bg-neutral-bg px-2 py-0.5 text-[12px] font-semibold text-neutral-text">
                            {{ $tag['count'] }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endsection
