<?php

/**
 * Le comptage affiché avant l'envoi et la liste réellement servie sortent de
 * la même requête : un agent ne doit pas voir un nombre puis en toucher un
 * autre.
 */

use App\Enums\CampaignAudience;
use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Services\Support\CampaignAudienceResolver;

it('counts every driver for the whole fleet', function (): void {
    Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(2)->create(['status' => DriverStatus::Suspended]);

    expect(app(CampaignAudienceResolver::class)->count(CampaignAudience::All))->toBe(5);
});

it('filters a segment by status', function (): void {
    Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(2)->create(['status' => DriverStatus::Suspended]);

    $count = app(CampaignAudienceResolver::class)
        ->count(CampaignAudience::Segment, ['status' => [DriverStatus::Active->value]]);

    expect($count)->toBe(3);
});

it('ignores an unknown status rather than returning nothing', function (): void {
    Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);

    $count = app(CampaignAudienceResolver::class)
        ->count(CampaignAudience::Segment, ['status' => ['inexistant']]);

    expect($count)->toBe(3);
});

it('excludes a soft deleted driver', function (): void {
    Driver::factory()->count(2)->create();
    Driver::factory()->create()->delete();

    expect(app(CampaignAudienceResolver::class)->count(CampaignAudience::All))->toBe(2);
});

it('targets only the named drivers', function (): void {
    $wanted = Driver::factory()->count(2)->create();
    Driver::factory()->count(3)->create();

    $count = app(CampaignAudienceResolver::class)
        ->count(CampaignAudience::Individual, ['driver_ids' => $wanted->pluck('id')->all()]);

    expect($count)->toBe(2);
});

it('returns nobody for an individual send with no driver named', function (): void {
    Driver::factory()->count(3)->create();

    expect(app(CampaignAudienceResolver::class)->count(CampaignAudience::Individual))->toBe(0);
});
