<?php

use Illuminate\Support\Facades\Blade;

it('maps tones and renders title, body and actions', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-banner tone="warn" title="3 messages sans ticket" pulse>
            Ils attendent une décision.
            <x-slot:actions><button data-act>Créer</button></x-slot:actions>
        </x-banner>
    BLADE);

    expect($html)->toContain('border-warn-text/20 bg-warn-bg text-warn-text')
        ->toContain('animate-pulse-soft')
        ->toContain('3 messages sans ticket')
        ->toContain('Ils attendent une décision.')
        ->toContain('data-act')
        ->not->toContain('role="alert"');
});

it('announces an error banner', function (): void {
    expect(Blade::render('<x-banner tone="err">Échec</x-banner>'))
        ->toContain('role="alert"')
        ->toContain('bg-err-bg');
});
