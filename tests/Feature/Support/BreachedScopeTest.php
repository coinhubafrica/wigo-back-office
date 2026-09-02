<?php

/**
 * `SupportRequest::scopeBreached()` est la traduction SQL de
 * `SlaCalculator::isBreached()`. Deux définitions du même mot : si elles
 * divergent, la file et le compteur ne comptent pas la même chose — et rien
 * ne le signalerait.
 */

use App\Models\SupportRequest;
use App\Services\Support\SlaCalculator;

it('counts a ticket that has missed its first response deadline', function (): void {
    $late = SupportRequest::factory()->create([
        'first_response_at' => null,
        'sla_first_response_due' => now()->subHour(),
        'sla_resolution_due' => now()->addDay(),
    ]);

    expect(SupportRequest::query()->breached()->pluck('id')->all())->toBe([$late->id]);
});

it('spares a ticket answered within its deadline', function (): void {
    SupportRequest::factory()->create([
        'first_response_at' => now()->subHours(2),
        'sla_first_response_due' => now()->subHour(),
        'sla_resolution_due' => now()->addDay(),
    ]);

    expect(SupportRequest::query()->breached()->count())->toBe(0);
});

it('counts a ticket still unresolved past its resolution deadline', function (): void {
    $late = SupportRequest::factory()->create([
        'first_response_at' => now()->subDay(),
        'sla_first_response_due' => now()->subDay(),
        'resolved_at' => null,
        'sla_resolution_due' => now()->subHour(),
    ]);

    expect(SupportRequest::query()->breached()->pluck('id')->all())->toBe([$late->id]);
});

it('spares a ticket resolved after its deadline', function (): void {
    // Répondre en retard reste un manquement, mais la question du scope est
    // « reste-t-il en souffrance maintenant » (cf. SlaCalculator::isBreached).
    SupportRequest::factory()->create([
        'first_response_at' => now()->subDay(),
        'sla_first_response_due' => now()->subDay(),
        'resolved_at' => now(),
        'sla_resolution_due' => now()->subHour(),
    ]);

    expect(SupportRequest::query()->breached()->count())->toBe(0);
});

it('agrees with the calculator on every shape of ticket', function (): void {
    // L'invariant qui compte : une seule définition de « en retard ».
    $shapes = [
        ['first_response_at' => null, 'sla_first_response_due' => now()->subHour(), 'sla_resolution_due' => now()->addDay(), 'resolved_at' => null],
        ['first_response_at' => null, 'sla_first_response_due' => now()->addHour(), 'sla_resolution_due' => now()->addDay(), 'resolved_at' => null],
        ['first_response_at' => now(), 'sla_first_response_due' => now()->subHour(), 'sla_resolution_due' => now()->addDay(), 'resolved_at' => null],
        ['first_response_at' => now(), 'sla_first_response_due' => now()->subDay(), 'sla_resolution_due' => now()->subHour(), 'resolved_at' => null],
        ['first_response_at' => now(), 'sla_first_response_due' => now()->subDay(), 'sla_resolution_due' => now()->subHour(), 'resolved_at' => now()],
    ];

    foreach ($shapes as $attributes) {
        SupportRequest::factory()->create($attributes);
    }

    $sla = app(SlaCalculator::class);
    $bySql = SupportRequest::query()->breached()->pluck('id')->sort()->values()->all();
    $byCalculator = SupportRequest::query()->get()
        ->filter(fn (SupportRequest $request): bool => $sla->isBreached($request))
        ->pluck('id')->sort()->values()->all();

    expect($bySql)->toBe($byCalculator)
        ->and($bySql)->not->toBeEmpty();
});
