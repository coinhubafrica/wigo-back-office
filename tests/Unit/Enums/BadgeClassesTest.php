<?php

/**
 * Toute énumération d'état expose `badgeClasses()` : une paire complète
 * `bg-… text-…` sur les jetons de la charte. `bg-zinc-100 text-zinc-500`
 * mesurait 4,40:1, sous le seuil AA — `neutral-bg/text` existe pour ça.
 */

use App\Enums\AuditAction;
use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\CnpsMonthStatus;
use App\Enums\DriverStatus;
use App\Enums\ShopOrderStatus;
use App\Enums\SupportRequestPriority;
use App\Enums\SupportRequestStatus;
use App\Enums\TransactionStatus;

it('returns a complete token pair for every case', function (string $enum): void {
    foreach ($enum::cases() as $case) {
        expect($case->badgeClasses())
            ->toMatch('/^bg-[a-z-]+ text-[a-z-]+$/')
            ->not->toContain('zinc');
    }
})->with([
    AuditAction::class,
    CampaignAudience::class,
    CampaignStatus::class,
    ChallengeStatus::class,
    ChallengeType::class,
    CnpsMonthStatus::class,
    DriverStatus::class,
    ShopOrderStatus::class,
    SupportRequestPriority::class,
    SupportRequestStatus::class,
    TransactionStatus::class,
]);
