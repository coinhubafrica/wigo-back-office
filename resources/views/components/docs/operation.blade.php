@props(['entry', 'spec', 'reference'])

{{--
    Le contrat d'une opération : paramètres, corps de requête, réponses.
    Extrait de l'ancienne page de référence pour être partagé.

    Les références internes sont résolues par `ApiReference::resolve()` : un
    noeud du contrat peut être un `$ref`, et le gabarit n'a pas à savoir
    comment on le suit.
--}}

@if (isset($entry['operation']['description']))
    <div class="docs-prose">
        {!! App\Support\Docs\DocsMarkdown::toHtml($entry['operation']['description']) !!}
    </div>
@endif

@if (! empty($entry['operation']['parameters']))
    <h2 class="mt-6 text-[12px] font-semibold uppercase tracking-wide text-muted">Paramètres</h2>
    <div class="mt-1.5 space-y-2">
        @foreach ($entry['operation']['parameters'] as $node)
            @php($parameter = $reference->resolve($node))
            <div>
                <p class="flex flex-wrap items-baseline gap-x-2">
                    <code class="font-mono text-[13px] font-semibold">{{ $parameter['name'] ?? '' }}</code>
                    <span class="text-[12px] text-muted">{{ $parameter['in'] ?? '' }}</span>
                    @if ($parameter['required'] ?? false)
                        <span class="rounded bg-err-bg px-1.5 py-0.5 text-[11px] font-semibold text-err-text">requis</span>
                    @endif
                </p>
                @if (isset($parameter['description']))
                    <p class="mt-0.5 text-[13px] text-muted">{{ $parameter['description'] }}</p>
                @endif
            </div>
        @endforeach
    </div>
@endif

@foreach ($entry['operation']['requestBody']['content'] ?? [] as $mediaType => $media)
    <h2 class="mt-6 text-[12px] font-semibold uppercase tracking-wide text-muted">
        Corps de la requête
        <span class="font-mono normal-case">{{ $mediaType }}</span>
    </h2>
    <div class="mt-1.5">
        <x-docs.schema :schema="$media['schema'] ?? []" :spec="$spec" />
    </div>
@endforeach

<h2 class="mt-6 text-[12px] font-semibold uppercase tracking-wide text-muted">Réponses</h2>
<div class="mt-1.5 space-y-3">
    @foreach ($entry['operation']['responses'] ?? [] as $status => $node)
        @php($response = $reference->resolve($node))
        <div>
            <p class="flex flex-wrap items-baseline gap-x-2">
                <span class="rounded px-1.5 py-0.5 font-mono text-[12px] font-semibold
                             {{ (int) $status < 300 ? 'bg-ok-bg text-ok-text' : 'bg-err-bg text-err-text' }}">
                    {{ $status }}
                </span>
                @if (($response['description'] ?? '') !== '')
                    <span class="text-[13px] text-muted">{{ $response['description'] }}</span>
                @endif
            </p>

            @foreach ($response['content'] ?? [] as $mediaType => $media)
                <div class="mt-1.5">
                    @if ($mediaType !== 'application/json')
                        <p class="text-[12px] text-muted">
                            <code class="font-mono">{{ $mediaType }}</code>
                        </p>
                    @endif
                    <x-docs.schema :schema="$media['schema'] ?? []" :spec="$spec" :depth="1" />
                </div>
            @endforeach
        </div>
    @endforeach
</div>
