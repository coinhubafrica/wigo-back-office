<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Permissions et rôles initiaux : nécessaires dans tous les
        // environnements, y compris en production.
        $this->call(RolePermissionSeeder::class);

        // Jeux d'essai : jamais exécutés en production.
        if (! app()->isProduction()) {
            $this->call([
                UserSeeder::class,
                DriverSeeder::class,
                AnnouncementSeeder::class,
                // ChallengeSeeder avant YangoOrderSeeder : le grand livre de
                // tickets ne mine que pour les challenges déjà existants au
                // moment où chaque journée est enregistrée.
                ChallengeSeeder::class,
                YangoOrderSeeder::class,
                CnpsSeeder::class,
                RechargeSeeder::class,
                ShopSeeder::class,
                SupportSeeder::class,
            ]);

            // Gel du pool de tirage "à effectuer" : nécessite les courses
            // d'YangoOrderSeeder, donc exécuté après coup plutôt que dans
            // ChallengeSeeder lui-même.
            (new ChallengeSeeder)->freezeDrawPendingFixture();
        }
    }
}
