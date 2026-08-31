<?php

/**
 * La priorité et les deux échéances se déduisent de la catégorie : l'agent ne
 * les saisit jamais.
 */

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Models\SupportRequest;
use App\Services\Support\SlaCalculator;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('derives a high priority and a one hour first response from a payment', function (): void {
    $request = SupportRequest::factory()->make([
        'category' => SupportRequestCategory::Payment,
        'created_at' => CarbonImmutable::parse('2026-09-01 08:00:00'),
    ]);

    app(SlaCalculator::class)->apply($request);

    expect($request->priority)->toBe(SupportRequestPriority::High)
        ->and($request->sla_first_response_due->toDateTimeString())->toBe('2026-09-01 09:00:00')
        ->and($request->sla_resolution_due->toDateTimeString())->toBe('2026-09-01 16:00:00');
});

it('derives a low priority from an uncategorised request', function (): void {
    $request = SupportRequest::factory()->make([
        'category' => SupportRequestCategory::Other,
        'created_at' => CarbonImmutable::parse('2026-09-01 08:00:00'),
    ]);

    app(SlaCalculator::class)->apply($request);

    expect($request->priority)->toBe(SupportRequestPriority::Low)
        // 24 h de première réponse, 5 jours de résolution.
        ->and($request->sla_first_response_due->toDateTimeString())->toBe('2026-09-02 08:00:00')
        ->and($request->sla_resolution_due->toDateTimeString())->toBe('2026-09-06 08:00:00');
});

it('counts the delay in real time across a weekend', function (): void {
    // Le support est ouvert en continu : aucun jour non ouvré ne se défalque.
    $request = SupportRequest::factory()->make([
        'category' => SupportRequestCategory::Payment,
        'created_at' => CarbonImmutable::parse('2026-09-05 22:00:00'), // un samedi
    ]);

    app(SlaCalculator::class)->apply($request);

    expect($request->sla_first_response_due->toDateTimeString())->toBe('2026-09-05 23:00:00');
});

it('recomputes the priority and both deadlines when the category changes', function (): void {
    $request = SupportRequest::factory()->make([
        'category' => SupportRequestCategory::Other,
        'created_at' => CarbonImmutable::parse('2026-09-01 08:00:00'),
    ]);

    $calculator = app(SlaCalculator::class);
    $calculator->apply($request);
    expect($request->priority)->toBe(SupportRequestPriority::Low);

    $calculator->recategorise($request, SupportRequestCategory::Payment);

    expect($request->category)->toBe(SupportRequestCategory::Payment)
        ->and($request->priority)->toBe(SupportRequestPriority::High)
        ->and($request->sla_first_response_due->toDateTimeString())->toBe('2026-09-01 09:00:00')
        ->and($request->recategorised_at)->not->toBeNull();
});

it('treats an unanswered request past its first response deadline as breached', function (): void {
    CarbonImmutable::setTestNow('2026-09-01 10:00:00');

    $request = SupportRequest::factory()->make([
        'first_response_at' => null,
        'sla_first_response_due' => CarbonImmutable::parse('2026-09-01 09:00:00'),
        'sla_resolution_due' => CarbonImmutable::parse('2026-09-02 09:00:00'),
        'resolved_at' => null,
    ]);

    expect(app(SlaCalculator::class)->isBreached($request))->toBeTrue();
});

it('does not treat an answered request as breached on the first response clock', function (): void {
    CarbonImmutable::setTestNow('2026-09-01 10:00:00');

    $request = SupportRequest::factory()->make([
        'first_response_at' => CarbonImmutable::parse('2026-09-01 08:30:00'),
        'sla_first_response_due' => CarbonImmutable::parse('2026-09-01 09:00:00'),
        'sla_resolution_due' => CarbonImmutable::parse('2026-09-02 09:00:00'),
        'resolved_at' => null,
    ]);

    expect(app(SlaCalculator::class)->isBreached($request))->toBeFalse();
});
