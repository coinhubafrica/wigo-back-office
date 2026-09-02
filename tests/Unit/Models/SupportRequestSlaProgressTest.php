<?php

/**
 * La jauge de la file : quel chronomètre court, et où il en est.
 */

use App\Models\SupportRequest;
use Carbon\CarbonImmutable;

function slaTicket(array $attributes): SupportRequest
{
    $request = new SupportRequest;
    $request->forceFill(['created_at' => CarbonImmutable::parse('2026-09-02 10:00'), ...$attributes]);

    return $request;
}

it('tracks the first response while none has been given', function (): void {
    $request = slaTicket([
        'first_response_at' => null,
        'sla_first_response_due' => '2026-09-02 12:00',
        'sla_resolution_due' => '2026-09-03 10:00',
    ]);

    $gauge = $request->slaProgress(CarbonImmutable::parse('2026-09-02 11:00'));

    expect($gauge['phase'])->toBe('first_response')
        ->and($gauge['ratio'])->toBe(0.5)
        ->and($gauge['overdue'])->toBeFalse();
});

it('switches to the resolution clock once answered', function (): void {
    $request = slaTicket([
        'first_response_at' => '2026-09-02 10:30',
        'sla_first_response_due' => '2026-09-02 12:00',
        'sla_resolution_due' => '2026-09-03 10:00',
    ]);

    $gauge = $request->slaProgress(CarbonImmutable::parse('2026-09-02 22:00'));

    expect($gauge['phase'])->toBe('resolution')
        ->and($gauge['ratio'])->toBe(0.5);
});

it('caps the ratio and flags the overrun', function (): void {
    $request = slaTicket([
        'first_response_at' => null,
        'sla_first_response_due' => '2026-09-02 12:00',
    ]);

    $gauge = $request->slaProgress(CarbonImmutable::parse('2026-09-02 15:00'));

    expect($gauge['ratio'])->toBe(1.0)
        ->and($gauge['overdue'])->toBeTrue();
});

it('has nothing to show once resolved', function (): void {
    $request = slaTicket([
        'first_response_at' => '2026-09-02 10:30',
        'resolved_at' => '2026-09-02 13:00',
        'sla_first_response_due' => '2026-09-02 12:00',
        'sla_resolution_due' => '2026-09-03 10:00',
    ]);

    expect($request->slaProgress())->toBeNull();
});
