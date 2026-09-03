<?php

/**
 * `x-field` : libellé, contrôle et erreur reliés par `for`/`id` et
 * `aria-describedby`, quel que soit le type de contrôle.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('derives the id from the name and binds the label', function (): void {
    $html = Blade::render('<x-field label="Longueur du code" name="otpLength" type="number" wire:model="otpLength" />');

    expect($html)->toContain('for="field-otplength"')
        ->toContain('id="field-otplength"')
        ->toContain('wire:model="otpLength"')
        ->toContain('type="number"')
        ->toContain('mb-1.5 block text-xs font-semibold text-muted')
        ->toContain('rounded border border-input')
        ->not->toContain('focus:outline-none');
});

it('respects an explicit id', function (): void {
    $html = Blade::render('<x-field label="E-mail" name="email" id="email" type="email" />');

    expect($html)->toContain('for="email"')
        ->toContain('id="email"')
        ->not->toContain('field-email');
});

it('renders select, textarea and search controls', function (): void {
    $select = Blade::render('<x-field label="Catégorie" name="category" type="select"><option>Panne</option></x-field>');
    $textarea = Blade::render('<x-field label="Message" name="body" type="textarea" rows="3" />');
    $search = Blade::render('<x-field label="Rechercher" name="search" type="search" label-hidden placeholder="Nom" />');

    expect($select)->toContain('<select')->toContain('<option>Panne</option>')
        ->and($textarea)->toContain('<textarea')->toContain('rows="3"')
        ->and($search)->toContain('type="search"')->toContain('pl-9')->toContain('sr-only');
});

it('shows the shared error bag message and marks the control invalid', function (): void {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['title' => ['Le titre est requis.']])));

    $html = Blade::render('<x-field label="Titre" name="title" hint="Court et précis." />');

    expect($html)->toContain('Le titre est requis.')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="field-title-error"')
        ->toContain('mt-1 text-sm text-err-text')
        ->not->toContain('Court et précis.');
});

it('shows the hint when there is no error', function (): void {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-field label="Titre" name="title" hint="Court et précis." />');

    expect($html)->toContain('Court et précis.')
        ->toContain('aria-describedby="field-title-hint"')
        ->not->toContain('aria-invalid');
});

it('lets an explicit error override the bag', function (): void {
    $html = Blade::render('<x-field label="Montant" name="amount" error="Trop élevé." />');

    expect($html)->toContain('Trop élevé.');
});

it('keeps layout classes on the wrapper, not the control', function (): void {
    $html = Blade::render('<x-field label="Nom" name="name" class="sm:col-span-2" placeholder="Nom" />');

    expect($html)->toMatch('/<div class="[^"]*sm:col-span-2/')
        ->toMatch('/<input[^>]*placeholder="Nom"/')
        ->not->toMatch('/<input[^>]*sm:col-span-2/');
});
