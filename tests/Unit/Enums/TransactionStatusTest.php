<?php

namespace Tests\Unit\Enums;

use App\Enums\TransactionStatus;
use PHPUnit\Framework\TestCase;

class TransactionStatusTest extends TestCase
{
    public function test_a_credited_transaction_is_final(): void
    {
        // C'est ce qui interdit le double crédit : rien ne part de `credited`.
        $this->assertTrue(TransactionStatus::Credited->isFinal());
        $this->assertSame([], TransactionStatus::Credited->allowedTransitions());
    }

    public function test_a_transaction_awaiting_credit_can_still_be_credited(): void
    {
        $this->assertTrue(TransactionStatus::Paid->allows(TransactionStatus::Credited));
        $this->assertTrue(TransactionStatus::ToReview->allows(TransactionStatus::Credited));
    }

    public function test_a_credited_transaction_allows_nothing(): void
    {
        foreach (TransactionStatus::cases() as $target) {
            $this->assertFalse(TransactionStatus::Credited->allows($target), $target->value);
        }
    }

    public function test_only_encashed_but_uncredited_transactions_are_replayable(): void
    {
        $this->assertTrue(TransactionStatus::ToReview->isReplayable());
        $this->assertTrue(TransactionStatus::Failed->isReplayable());
        $this->assertFalse(TransactionStatus::Credited->isReplayable());
        $this->assertFalse(TransactionStatus::Initiated->isReplayable());
    }

    public function test_the_wire_status_narrows_the_storage_set(): void
    {
        // Le mobile ne distingue pas l'échec de paiement du crédit refusé :
        // dans les deux cas le solde du conducteur n'a pas bougé.
        $this->assertSame('pending', TransactionStatus::Initiated->wireStatus());
        $this->assertSame('pending', TransactionStatus::Paid->wireStatus());
        $this->assertSame('credited', TransactionStatus::Credited->wireStatus());
        $this->assertSame('failed', TransactionStatus::Failed->wireStatus());
        $this->assertSame('failed', TransactionStatus::ToReview->wireStatus());
    }

    public function test_every_status_carries_a_label_and_a_badge(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertStringContainsString('bg-', $status->badgeClasses());
        }
    }
}
