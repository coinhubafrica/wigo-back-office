@extends('layouts.docs', [
    'current' => 'reference',
    'currentTag' => $entry['tagSlug'],
    'currentOperation' => $entry['id'],
    // Deux colonnes : la vue en profite pour occuper l'espace disponible
    // plutôt que d'être bridée à la largeur de lecture d'un guide.
    'containerClass' => 'max-w-6xl',
])

@php
    $title = $entry['summary'] ?? strtoupper($entry['method']).' '.$entry['path'];

    // Le premier succès documenté (2xx), pour l'exemple de réponse.
    $successStatus = null;

    foreach (array_keys($entry['operation']['responses'] ?? []) as $status) {
        if ((int) $status < 300) {
            $successStatus = $status;
            break;
        }
    }

    $successExample = null;

    if ($successStatus !== null) {
        $response = $reference->resolve($entry['operation']['responses'][$successStatus]);
        $schema = $response['content']['application/json']['schema'] ?? null;

        if (is_array($schema)) {
            $successExample = $reference->responseExample($reference->resolve($schema));
        }
    }

    $signed = $reference->requiresSignedUrl($entry['method'], $entry['path']);
@endphp

@section('content')
    <nav aria-label="Fil d'Ariane" class="mb-2 text-[13px] text-muted">
        <a href="{{ App\Support\Docs\DocsMarkdown::url(route('docs.reference')) }}"
           class="hover:text-ink">Contrat REST</a>
        <span aria-hidden="true"> / </span>
        <a href="{{ App\Support\Docs\DocsMarkdown::url(route('docs.tag', ['tag' => $entry['tagSlug']])) }}"
           class="hover:text-ink">{{ $entry['tag'] }}</a>
        <span aria-hidden="true"> / </span>
        <span>{{ $entry['id'] }}</span>
    </nav>

    <header class="mb-6 border-b border-line pb-4">
        <p class="flex flex-wrap items-center gap-2">
            <x-docs.method :method="$entry['method']" class="text-[12px]" />
            <code class="font-mono text-sm font-semibold">{{ $reference->basePath() }}{{ $entry['path'] }}</code>
            @if ($entry['public'])
                <span class="rounded bg-neutral-bg px-1.5 py-0.5 text-[11px] font-semibold text-neutral-text">
                    public
                </span>
            @endif
        </p>

        @if ($entry['summary'] !== null)
            <h1 class="mt-2 text-2xl font-semibold">{{ $entry['summary'] }}</h1>
        @endif

        <p class="mt-1 font-mono text-[12px] text-muted">{{ $entry['id'] }}</p>
    </header>

    <div class="grid gap-8 lg:grid-cols-[1fr_minmax(0,420px)] lg:items-start">
        <div class="min-w-0">
            <x-docs.operation :entry="$entry" :spec="$spec" :reference="$reference" />
        </div>

        {{-- Colonne d'exemples, collante : reste visible pendant la lecture de
             la documentation à gauche. Passe sous la doc en dessous de `lg`. --}}
        <div class="space-y-4 lg:sticky lg:top-6 lg:self-start">
            @if ($signed)
                <p class="rounded bg-warn-bg px-3 py-2 text-[13px] text-warn-text">
                    Cette ressource exige une <strong>URL signée</strong> : un appel direct répondra 403,
                    quel que soit le jeton. L'URL signée est rendue par l'opération qui la produit.
                </p>
            @endif

            <x-docs.code-panel title="Requête" language="bash"
                                :code="$reference->curlExample($entry['method'], $entry['path'], $entry['operation'])">
                <x-slot:badge>
                    <x-docs.method :method="$entry['method']" class="text-[10px]" />
                </x-slot:badge>
            </x-docs.code-panel>

            @if ($successExample !== null)
                <x-docs.code-panel title="Exemple de réponse" language="json" :code="$successExample">
                    <x-slot:badge>
                        <span class="rounded bg-ok-bg px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ok-text">
                            {{ $successStatus }}
                        </span>
                    </x-slot:badge>
                </x-docs.code-panel>
            @endif
        </div>
    </div>
@endsection
