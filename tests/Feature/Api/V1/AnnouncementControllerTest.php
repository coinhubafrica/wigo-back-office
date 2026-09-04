<?php

use App\Models\Announcement;
use App\Models\Driver;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.announcements.index'))->assertUnauthorized();
});

it('lists only active announcements in order', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    Announcement::factory()->create(['title' => 'Second', 'order' => 2, 'is_active' => true]);
    Announcement::factory()->create(['title' => 'First', 'order' => 1, 'is_active' => true]);
    Announcement::factory()->inactive()->create(['title' => 'Paused']);

    $response = $this->getJson(route('api.v1.announcements.index'))->assertOk();

    $response->assertJsonPath('data.0.title', 'First');
    $response->assertJsonPath('data.1.title', 'Second');
    $response->assertJsonCount(2, 'data');
});

it('excludes announcements outside their scheduling window', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    Announcement::factory()->create(['title' => 'Not yet', 'starts_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Expired', 'ends_at' => now()->subDay()]);
    Announcement::factory()->create(['title' => 'Live now', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    $response = $this->getJson(route('api.v1.announcements.index'))->assertOk();

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.title', 'Live now');
});

it('paginates by cursor and caps the page size', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    foreach (range(1, 5) as $order) {
        Announcement::factory()->create(['title' => "Bannière {$order}", 'order' => $order]);
    }

    $first = $this->getJson(route('api.v1.announcements.index', ['per_page' => 2]))->assertOk();

    $first->assertJsonCount(2, 'data');
    $first->assertJsonPath('meta.per_page', 2);
    $first->assertJsonPath('data.0.title', 'Bannière 1');

    $cursor = $first->json('meta.next_cursor');
    $this->assertNotNull($cursor);

    // La page suivante enchaîne sans répéter ni sauter d'annonce.
    $this->getJson(route('api.v1.announcements.index', ['per_page' => 2, 'cursor' => $cursor]))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Bannière 3');
});

it('caps the page size at fifty', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);
    Announcement::factory()->create();

    $this->getJson(route('api.v1.announcements.index', ['per_page' => 500]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

it('exposes the expected fields', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    Announcement::factory()->create([
        'title' => 'JCBL 2026',
        'media_url' => 'announcements/banniere.jpg',
        'duration' => 8,
        'order' => 1,
    ]);

    $this->getJson(route('api.v1.announcements.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'title', 'media_type', 'media_url', 'duration', 'order']],
        ])
        ->assertJsonPath('data.0.title', 'JCBL 2026')
        // Le carrousel mobile fait défiler la diapositive au bout de ce délai.
        ->assertJsonPath('data.0.duration', 8);
});
