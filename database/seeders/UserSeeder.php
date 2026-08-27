<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Utilisateurs de développement, un par rôle du cahier des charges. Les
 * identifiants reprennent ceux du prototype pour servir de jeu d'essai stable.
 *
 * Idempotent : la clé est l'adresse e-mail.
 */
class UserSeeder extends Seeder
{
    private const PASSWORD = 'wigo2026';

    public function run(): void
    {
        $users = [
            ['direction@atconfortplus.ci', 'Éric', "N'GUESSAN", 'direction'],
            ['gestionnaire@atconfortplus.ci', 'Mariam', 'KONÉ', 'gestionnaire'],
            ['bonus@atconfortplus.ci', 'Sylvain', 'ADJÉ', 'bonus'],
            ['stock@atconfortplus.ci', 'Awa', 'CISSÉ', 'stock'],
            ['admin@atconfortplus.ci', 'Franck', 'ZADI', 'admin'],
        ];

        foreach ($users as [$email, $firstName, $lastName, $role]) {
            $user = User::withTrashed()->firstOrNew(['email' => $email]);

            $user->forceFill([
                'name' => "{$firstName} {$lastName}",
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => '+225'.fake()->numerify('##########'),
                'password' => self::PASSWORD,
                'is_active' => true,
                'email_verified_at' => now(),
                'deleted_at' => null,
            ])->save();

            $user->syncRoles([$role]);
        }

        // Compte désactivé : vérifie le refus de connexion et la déconnexion
        // forcée d'une session en cours.
        $disabled = User::withTrashed()->firstOrNew(['email' => 'desactive@atconfortplus.ci']);
        $disabled->forceFill([
            'name' => 'Compte Désactivé',
            'first_name' => 'Compte',
            'last_name' => 'Désactivé',
            'password' => self::PASSWORD,
            'is_active' => false,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ])->save();
        $disabled->syncRoles(['gestionnaire']);

        $this->command->table(
            ['Adresse e-mail', 'Rôle', 'Mot de passe'],
            [
                ['direction@atconfortplus.ci', 'direction — tous les modules', self::PASSWORD],
                ['gestionnaire@atconfortplus.ci', 'gestionnaire', self::PASSWORD],
                ['bonus@atconfortplus.ci', 'bonus — dont challenges', self::PASSWORD],
                ['stock@atconfortplus.ci', 'stock', self::PASSWORD],
                ['admin@atconfortplus.ci', 'admin', self::PASSWORD],
                ['desactive@atconfortplus.ci', 'compte désactivé', self::PASSWORD],
            ],
        );
    }
}
