{{--
    Pagination des listes Livewire (`$rows->links()`), sur les jetons de la
    charte. Vue publiée depuis `livewire::tailwind` et réécrite : la version
    d'origine parlait anglais et gris Tailwind, et effaçait l'anneau de focus.

    Les appels `previousPage`/`nextPage`/`gotoPage` et `wire:key` suivent la
    convention de Livewire pour que plusieurs paginateurs cohabitent.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $pageName = $paginator->getPageName();
    $suffix = $pageName === 'page' ? '' : '.'.$pageName;
    $button = 'inline-flex size-8 items-center justify-center rounded text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav aria-label="{{ __('backoffice.common.pagination_nav') }}" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-muted tabular-nums">
                {{ __('backoffice.common.pagination_summary', [
                    'from' => number_format($paginator->firstItem() ?? 0, 0, ',', ' '),
                    'to' => number_format($paginator->lastItem() ?? 0, 0, ',', ' '),
                    'total' => number_format($paginator->total(), 0, ',', ' '),
                ]) }}
            </p>

            <div class="flex items-center gap-1">
                <button type="button"
                        wire:click="previousPage('{{ $pageName }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                        wire:loading.attr="disabled"
                        dusk="previousPage{{ $suffix }}"
                        @disabled($paginator->onFirstPage())
                        aria-label="{{ __('pagination.previous') }}"
                        class="{{ $button }} border border-line bg-card text-ink hover:bg-surface">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-hidden="true" class="inline-flex size-8 items-center justify-center text-xs text-muted">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <span wire:key="paginator-{{ $pageName }}-page-{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="{{ $button }} bg-primary text-white">{{ $page }}</span>
                                @else
                                    <button type="button"
                                            wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                            wire:loading.attr="disabled"
                                            aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                            class="{{ $button }} text-ink hover:bg-surface">{{ $page }}</button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach

                <button type="button"
                        wire:click="nextPage('{{ $pageName }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                        wire:loading.attr="disabled"
                        dusk="nextPage{{ $suffix }}"
                        @disabled(! $paginator->hasMorePages())
                        aria-label="{{ __('pagination.next') }}"
                        class="{{ $button }} border border-line bg-card text-ink hover:bg-surface">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
        </nav>
    @endif
</div>
