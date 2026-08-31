<?php

/**
 * Qui peut s'abonner à quoi.
 *
 * Le fil d'un conducteur est privé à deux parties : lui-même et les agents
 * habilités. Tout le reste est refusé — y compris un identifiant inconnu, qui
 * répond 403 plutôt que 404 et ne renseigne donc personne.
 */

use App\Models\Conversation;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Signer une réponse d'autorisation demande un vrai pilote, alors que la suite
| tourne sur `null` pour qu'aucune diffusion ne parte.
|
| Bascule donc la connexion, puis recharge `routes/channels.php` : les règles
| s'enregistrent sur le pilote par défaut de l'instant (`Broadcast::channel()`
| passe par `__call`), et celui-ci vient de changer. Sans ce rechargement,
| toutes les autorisations échoueraient — sans bruit, puisqu'un canal inconnu
| se refuse de toute façon.
|
| Aucun appel réseau n'est émis : signer ne demande qu'une clé et un secret.
*/
beforeEach(function (): void {
    config(['broadcasting.default' => 'reverb']);

    require base_path('routes/channels.php');

    $this->seed(RolePermissionSeeder::class);
});

function authoriseAsStaff(string $role, string $channel): TestResponse
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '1234.5678',
    ]);
}

function authoriseAsDriver(Driver $driver, string $channel): TestResponse
{
    Sanctum::actingAs($driver, ['mobile:*']);

    return test()->postJson('/api/v1/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '1234.5678',
    ]);
}

it('lets an authorised agent join a conversation', function (): void {
    $conversation = Conversation::factory()->create();

    authoriseAsStaff('gestionnaire', 'private-conversation.'.$conversation->id)->assertOk();
});

it('refuses an agent without the support permission', function (): void {
    $conversation = Conversation::factory()->create();

    authoriseAsStaff('admin', 'private-conversation.'.$conversation->id)->assertForbidden();
});

it('lets a driver join their own conversation', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);

    authoriseAsDriver($driver, 'private-conversation.'.$conversation->id)->assertOk();
});

it('refuses a driver on another conversation', function (): void {
    // Le test qui compte : rien ne doit fuir d'un compte à l'autre.
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $other->id]);

    authoriseAsDriver($mine, 'private-conversation.'.$conversation->id)->assertForbidden();
});

it('refuses an unknown conversation without saying so', function (): void {
    // 403 et non 404 : la réponse ne dit pas si le fil existe.
    authoriseAsStaff('gestionnaire', 'private-conversation.01m0000000000000000000000')
        ->assertForbidden();
});

it('lets an authorised agent join the queue', function (): void {
    authoriseAsStaff('gestionnaire', 'private-support-queue')->assertOk();
});

it('refuses a driver on the queue', function (): void {
    // La file est le tableau de bord des agents ; un conducteur n'y a rien à
    // voir, ni les fils des autres.
    authoriseAsDriver(Driver::factory()->create(), 'private-support-queue')->assertForbidden();
});

it('lets a driver join their own channel', function (): void {
    $driver = Driver::factory()->create();

    authoriseAsDriver($driver, 'private-driver.'.$driver->id)->assertOk();
});

it('refuses a driver on another drivers channel', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    authoriseAsDriver($mine, 'private-driver.'.$other->id)->assertForbidden();
});

it('refuses an unauthenticated subscription', function (): void {
    $conversation = Conversation::factory()->create();

    $this->postJson('/api/v1/broadcasting/auth', [
        'channel_name' => 'private-conversation.'.$conversation->id,
        'socket_id' => '1234.5678',
    ])->assertUnauthorized();
});
