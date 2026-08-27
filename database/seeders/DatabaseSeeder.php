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
            ]);
        }
    }
}
