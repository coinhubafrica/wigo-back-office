<?php

use Illuminate\Support\Facades\Blade;

it('aligns the end slot to the right without a spacer span', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-toolbar class="mt-4">
            <input data-search>
            <x-slot:end><button data-new>Nouveau</button></x-slot:end>
        </x-toolbar>
    BLADE);

    expect($html)->toContain('flex flex-wrap items-center gap-3')
        ->toContain('mt-4')
        ->toContain('ml-auto')
        ->toContain('data-new')
        ->not->toContain('<span class="flex-1">');
});
