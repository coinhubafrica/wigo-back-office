<?php

use Illuminate\Support\Facades\Blade;

it('renders initials in three sizes', function (string $size, string $classes): void {
    expect(Blade::render("<x-avatar initials=\"AC\" size=\"{$size}\" />"))
        ->toContain('AC')
        ->toContain($classes)
        ->toContain('aria-hidden="true"');
})->with([
    ['sm', 'size-7'],
    ['md', 'size-9'],
    ['lg', 'size-11'],
]);

it('renders a photo when given a source', function (): void {
    $html = Blade::render('<x-avatar initials="AC" src="/photo.jpg" alt="Abdoul COMBA" />');

    expect($html)->toContain('<img')
        ->toContain('src="/photo.jpg"')
        ->toContain('alt="Abdoul COMBA"')
        ->not->toContain('>AC<');
});

it('names the initials when they stand alone', function (): void {
    expect(Blade::render('<x-avatar initials="AC" alt="Abdoul COMBA" />'))
        ->toContain('role="img"')
        ->toContain('aria-label="Abdoul COMBA"');
});
