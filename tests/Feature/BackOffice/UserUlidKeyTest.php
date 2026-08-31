<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Notifications\Notification;

/**
 * `users.id` est un ULID, comme le reste du schéma.
 *
 * La notification en base se vérifie ici parce qu'elle était cassée tant que
 * la clé restait auto-incrémentée : `notifications` déclare son morph en
 * `ulidMorphs`, donc un entier n'y tenait pas. (`personal_access_tokens` a le
 * même morph, mais le back-office s'authentifie par session : `User` ne porte
 * pas `HasApiTokens`, seul `Driver` en émet.)
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a user identifier is a ulid', function (): void {
    $user = User::factory()->create();

    $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $user->id);
});

it('a user keeps its roles through the ulid morph key', function (): void {
    $user = User::factory()->create();
    $user->assignRole('direction');

    // 'user' et non le nom de classe : la carte de morph est appliquée
    // globalement (cf. AppServiceProvider::configureModels()).
    $this->assertDatabaseHas('model_has_roles', [
        'model_type' => 'user',
        'model_uuid' => $user->id,
    ]);

    $this->assertTrue($user->fresh()?->hasRole('direction'));
});

it('a user can receive a database notification', function (): void {
    $user = User::factory()->create();

    $user->notify(new UserUlidKeyTestNotification);

    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
    ]);
});

/**
 * Notification minimale : seul le canal `database` nous intéresse ici.
 */
class UserUlidKeyTestNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'test'];
    }
}
