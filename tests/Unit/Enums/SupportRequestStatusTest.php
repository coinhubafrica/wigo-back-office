<?php

use App\Enums\SupportRequestStatus;

it('names every status in French', function (): void {
    // « Close » traînait au milieu d'une énumération française.
    expect(SupportRequestStatus::Closed->label())->toBe('Fermée')
        ->and(SupportRequestStatus::Resolved->label())->toBe('Résolue');
});

it('carries a label, a hint and a badge on every status', function (): void {
    // La légende de l'écran sort d'ici : un état ajouté sans définition
    // ramènerait l'ambiguïté que cette légende corrige.
    foreach (SupportRequestStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->hint())->not->toBe('')
            ->and($status->badgeClasses())->toContain('bg-');
    }
});

it('distinguishes each status from the others', function (): void {
    $labels = array_map(fn (SupportRequestStatus $s): string => $s->label(), SupportRequestStatus::cases());
    $hints = array_map(fn (SupportRequestStatus $s): string => $s->hint(), SupportRequestStatus::cases());

    expect($labels)->toBe(array_unique($labels))
        ->and($hints)->toBe(array_unique($hints));
});

it('keeps the alert colour for the sla breach alone', function (): void {
    // Un ticket ouvert est le cas normal : le teinter en alerte rendait le
    // badge « En retard » indistinguable.
    expect(SupportRequestStatus::Open->badgeClasses())->not->toContain('err');
});

it('counts open and pending as still in the queue', function (): void {
    expect(SupportRequestStatus::Open->isLive())->toBeTrue()
        ->and(SupportRequestStatus::Pending->isLive())->toBeTrue()
        ->and(SupportRequestStatus::Resolved->isLive())->toBeFalse()
        ->and(SupportRequestStatus::Closed->isLive())->toBeFalse();
});
