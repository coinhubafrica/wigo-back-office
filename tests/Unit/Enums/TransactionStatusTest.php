<?php

use App\Enums\TransactionStatus;

it('treats a credited transaction as final', function (): void {
    // C'est ce qui interdit le double crédit : rien ne part de `credited`.
    expect(TransactionStatus::Credited->isFinal())->toBeTrue()
        ->and(TransactionStatus::Credited->allowedTransitions())->toBe([]);
});

it('still allows crediting a transaction awaiting credit', function (): void {
    expect(TransactionStatus::Paid->allows(TransactionStatus::Credited))->toBeTrue()
        ->and(TransactionStatus::ToReview->allows(TransactionStatus::Credited))->toBeTrue();
});

it('allows nothing from a credited transaction', function (): void {
    foreach (TransactionStatus::cases() as $target) {
        expect(TransactionStatus::Credited->allows($target))->toBeFalse($target->value);
    }
});

it('only replays encashed but uncredited transactions', function (): void {
    expect(TransactionStatus::ToReview->isReplayable())->toBeTrue()
        ->and(TransactionStatus::Failed->isReplayable())->toBeTrue()
        ->and(TransactionStatus::Credited->isReplayable())->toBeFalse()
        ->and(TransactionStatus::Initiated->isReplayable())->toBeFalse();
});

it('narrows the storage set down to the wire status', function (): void {
    // Le mobile ne distingue pas l'échec de paiement du crédit refusé :
    // dans les deux cas le solde du conducteur n'a pas bougé.
    expect(TransactionStatus::Initiated->wireStatus())->toBe('pending')
        ->and(TransactionStatus::Paid->wireStatus())->toBe('pending')
        ->and(TransactionStatus::Credited->wireStatus())->toBe('credited')
        ->and(TransactionStatus::Failed->wireStatus())->toBe('failed')
        ->and(TransactionStatus::ToReview->wireStatus())->toBe('failed');
});

it('carries a label and a badge on every status', function (): void {
    foreach (TransactionStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->badgeClasses())->toContain('bg-');
    }
});
