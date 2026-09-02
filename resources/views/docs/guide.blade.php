@extends('layouts.docs', ['current' => $guide->slug])

@php($title = $guide->title)
@php($sections = $guide->tableOfContents())

@section('content')
    <header class="mb-6 border-b border-line pb-4">
        <h1 class="text-2xl font-semibold">{{ $guide->title }}</h1>
        <p class="mt-1 text-sm text-muted">
            Source : <code class="font-mono text-[13px]">{{ $guide->file }}</code>
        </p>
    </header>

    {{-- Le Markdown du dépôt, converti par DocsGuide : il est échappé à la
         conversion (`html_input: escape`), donc sûr à insérer tel quel. --}}
    <div class="docs-prose">
        {!! $guide->html() !!}
    </div>
@endsection
