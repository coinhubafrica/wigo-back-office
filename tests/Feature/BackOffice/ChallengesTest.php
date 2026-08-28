<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Livewire\Challenges\Prizes;
use App\Models\Challenge;
use App\Models\Prize;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChallengesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_permitted_user_reaches_the_challenges_page(): void
    {
        Challenge::factory()->create(['name' => 'Top 100 hebdo test']);

        $this->actingAs($this->user('bonus'))
            ->get(route(BackOfficeModule::Challenges->route()))
            ->assertOk()
            ->assertSee('Top 100 hebdo test');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Challenges->route()))
            ->assertForbidden();
    }

    public function test_direction_also_reaches_the_challenges_page(): void
    {
        $this->actingAs($this->user('direction'))
            ->get(route(BackOfficeModule::Challenges->route()))
            ->assertOk();
    }

    public function test_a_permitted_user_reaches_the_prizes_page(): void
    {
        Prize::factory()->create(['name' => 'Réfrigérateur test']);

        $this->actingAs($this->user('bonus'))
            ->get(route('bo.challenges.prizes'))
            ->assertOk()
            ->assertSee('Réfrigérateur test');
    }

    public function test_a_user_without_the_permission_cannot_reach_the_prizes_page(): void
    {
        $this->actingAs($this->user('stock'))
            ->get(route('bo.challenges.prizes'))
            ->assertForbidden();
    }

    public function test_a_prize_can_be_created(): void
    {
        Livewire::actingAs($this->user('bonus'))
            ->test(Prizes::class)
            ->call('newPrize')
            ->set('name', 'Cuisinière')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('prizes', ['name' => 'Cuisinière']);
    }

    public function test_a_prize_attached_to_a_challenge_cannot_be_deleted(): void
    {
        $prize = Prize::factory()->create();
        Challenge::factory()->raffle()->create(['prize_id' => $prize->id]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Prizes::class)
            ->call('confirmDelete', $prize->id)
            ->call('delete');

        $this->assertModelExists($prize);
    }

    public function test_an_unused_prize_can_be_deleted(): void
    {
        $prize = Prize::factory()->create();

        Livewire::actingAs($this->user('bonus'))
            ->test(Prizes::class)
            ->call('confirmDelete', $prize->id)
            ->call('delete');

        $this->assertModelMissing($prize);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
