@extends('layouts.docs', ['current' => 'reference', 'currentTag' => $tag['slug']])

@php
    /*
     * Index d'un module : de quoi choisir une opération, pas son contrat.
     */
    $title = $tag['name'];
@endphp

@section('content')
    <nav aria-label="Fil d'Ariane" class="mb-2 text-[13px] text-muted">
        <a href="{{ App\Support\Docs\DocsMarkdown::url(route('docs.reference')) }}"
           class="hover:text-ink">Contrat REST</a>
        <span aria-hidden="true"> / </span>
        <span>{{ $tag['name'] }}</span>
    </nav>

    <header class="mb-6 border-b border-line pb-4">
        <h1 class="text-2xl font-semibold">{{ $tag['name'] }}</h1>
        @if ($tag['description'] !== null)
            <p class="mt-1 text-sm text-muted">{{ $tag['description'] }}</p>
        @endif
        <p class="mt-1 text-sm text-muted">
            {{ $tag['count'] }} {{ $tag['count'] > 1 ? 'opérations' : 'opération' }}.
        </p>
    </header>

    <ul class="space-y-2">
        @foreach ($operations as $entry)
            <x-docs.operation-card
                :entry="$entry"
                :href="App\Support\Docs\DocsMarkdown::url(route('docs.operation', [
                    'tag' => $entry['tagSlug'],
                    'operation' => $entry['id'],
                ]))" />
        @endforeach
    </ul>
@endsection
