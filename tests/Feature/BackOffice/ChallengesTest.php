<?php

use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Livewire\Challenges\Prizes;
use App\Livewire\Challenges\Show;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\Prize;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the challenges page', function (): void {
    Challenge::factory()->create(['name' => 'Top 100 hebdo test']);

    $this->actingAs(challengesUser('bonus'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertOk()
        ->assertSee('Top 100 hebdo test');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(challengesUser('stock'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertForbidden();
});

it('direction also reaches the challenges page', function (): void {
    $this->actingAs(challengesUser('direction'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertOk();
});

it('a permitted user reaches the prizes page', function (): void {
    Prize::factory()->create(['name' => 'Réfrigérateur test']);

    $this->actingAs(challengesUser('bonus'))
        ->get(route('bo.challenges.prizes'))
        ->assertOk()
        ->assertSee('Réfrigérateur test');
});

it('a user without the permission cannot reach the prizes page', function (): void {
    $this->actingAs(challengesUser('stock'))
        ->get(route('bo.challenges.prizes'))
        ->assertForbidden();
});

it('a prize can be created', function (): void {
    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('newPrize')
        ->set('name', 'Cuisinière')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('prizes', ['name' => 'Cuisinière']);
});

it('a prize attached to a challenge cannot be deleted', function (): void {
    $prize = Prize::factory()->create();
    Challenge::factory()->raffle()->create(['prize_id' => $prize->id]);

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('confirmDelete', $prize->id)
        ->call('delete');

    $this->assertModelExists($prize);
});

it('an unused prize can be deleted', function (): void {
    $prize = Prize::factory()->create();

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('confirmDelete', $prize->id)
        ->call('delete');

    $this->assertModelMissing($prize);
});

it('crediting all winners requires a confirmation', function (): void {
    $challenge = Challenge::factory()->create(['status' => ChallengeStatus::PayoutPending]);
    $winner = ChallengeWinner::factory()->for($challenge)->create(['credited' => false]);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('confirmAction', 'credit_all')
        ->assertSet('pendingAction', 'credit_all')
        ->call('creditAll')
        ->assertSet('pendingAction', null);

    $this->assertTrue($winner->refresh()->credited);
});

it('cancelling the confirmation leaves winners uncredited', function (): void {
    $challenge = Challenge::factory()->create(['status' => ChallengeStatus::PayoutPending]);
    $winner = ChallengeWinner::factory()->for($challenge)->create(['credited' => false]);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('confirmAction', 'credit_all')
        ->call('cancelAction')
        ->assertSet('pendingAction', null);

    $this->assertFalse($winner->refresh()->credited);
});

function challengesUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
