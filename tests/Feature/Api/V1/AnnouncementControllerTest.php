<?php

namespace Tests\Feature\Api\V1;

use App\Models\Announcement;
use App\Models\Driver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.v1.announcements.index'))->assertUnauthorized();
    }

    public function test_it_lists_only_active_announcements_in_order(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Announcement::factory()->create(['title' => 'Second', 'order' => 2, 'is_active' => true]);
        Announcement::factory()->create(['title' => 'First', 'order' => 1, 'is_active' => true]);
        Announcement::factory()->inactive()->create(['title' => 'Paused']);

        $response = $this->getJson(route('api.v1.announcements.index'))->assertOk();

        $response->assertJsonPath('data.0.title', 'First');
        $response->assertJsonPath('data.1.title', 'Second');
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_excludes_announcements_outside_their_scheduling_window(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Announcement::factory()->create(['title' => 'Not yet', 'starts_at' => now()->addDay()]);
        Announcement::factory()->create(['title' => 'Expired', 'ends_at' => now()->subDay()]);
        Announcement::factory()->create(['title' => 'Live now', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $response = $this->getJson(route('api.v1.announcements.index'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Live now');
    }

    public function test_it_exposes_the_expected_fields(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Announcement::factory()->create([
            'title' => 'JCBL 2026',
            'media_url' => 'announcements/banniere.jpg',
            'order' => 1,
        ]);

        $this->getJson(route('api.v1.announcements.index'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'title', 'media_type', 'media_url', 'order']],
            ])
            ->assertJsonPath('data.0.title', 'JCBL 2026');
    }
}
