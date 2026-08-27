<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Conducteurs de développement couvrant les états sur lesquels les endpoints
 * d'authentification se ramifient. Les numéros sont fixes et mémorisables :
 * ils servent de jeu d'essai stable pour l'équipe mobile.
 *
 * Chaque conducteur est créé de façon idempotente (clé : le numéro), ce qui
 * permet de rejouer le seeder sans dupliquer les lignes.
 */
class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $this->driver(
            phone: '+2250717738299',
            attributes: [
                'yango_id' => 'yango-driver-001',
                'first_name' => 'Abdoul Aziz',
                'last_name' => 'COMBA',
                'license_number' => 'COMB012500370370A',
                'status' => DriverStatus::Active,
            ],
            vehicle: [
                'yango_id' => 'yango-vehicle-001',
                'plate_number' => 'AA-567-HJ',
                'brand' => 'Suzuki',
                'model' => 'Dzire',
                'color' => 'Blanc',
            ],
        );

        // Compte suspendu : /me répond 200 avec status=suspended, les
        // ressources métier répondront 403 avec le motif.
        $this->driver(
            phone: '+2250700000002',
            attributes: [
                'yango_id' => 'yango-driver-002',
                'first_name' => 'Mariam',
                'last_name' => 'TRAORE',
                'status' => DriverStatus::Suspended,
                'suspension_reason' => 'Documents non conformes',
            ],
            vehicle: [
                'yango_id' => 'yango-vehicle-002',
                'plate_number' => 'BB-234-KL',
                'brand' => 'Toyota',
                'model' => 'Yaris',
                'color' => 'Gris',
            ],
        );

        // Compte dormant : authentification normale, non bloqué.
        $this->driver(
            phone: '+2250700000003',
            attributes: [
                'yango_id' => 'yango-driver-003',
                'first_name' => 'Yao',
                'last_name' => 'KOFFI',
                'status' => DriverStatus::Dormant,
            ],
        );

        // CGU non acceptées : `terms.accepted` vaut false à la vérification.
        $this->driver(
            phone: '+2250700000004',
            attributes: [
                'yango_id' => 'yango-driver-004',
                'first_name' => 'Fatoumata',
                'last_name' => 'BAMBA',
                'terms_version' => null,
                'terms_accepted_at' => null,
            ],
        );

        // Pas encore rapproché du parc Yango : aucune donnée Fleet à synchroniser.
        $this->driver(
            phone: '+2250700000005',
            attributes: [
                'yango_id' => null,
                'first_name' => 'Ibrahim',
                'last_name' => 'DIALLO',
                'last_sync_at' => null,
            ],
        );

        // Verrouillé : toute demande ou vérification d'OTP répond 422.
        $locked = $this->driver(
            phone: '+2250700000006',
            attributes: [
                'yango_id' => 'yango-driver-006',
                'first_name' => 'Aya',
                'last_name' => 'N\'GUESSAN',
            ],
        );

        if (! $locked->otpCodes()->whereNotNull('locked_until')->exists()) {
            OtpCode::factory()->for($locked)->locked()->create();
        }

        // Sans véhicule affecté : `vehicle` est null dans le profil.
        $this->driver(
            phone: '+2250700000007',
            attributes: [
                'yango_id' => 'yango-driver-007',
                'first_name' => 'Seydou',
                'last_name' => 'OUATTARA',
            ],
        );

        $this->command->table(
            ['Téléphone', 'Nom', 'Statut', 'Particularité'],
            [
                ['+2250717738299', 'Abdoul Aziz COMBA', 'active', 'nominal, véhicule affecté'],
                ['+2250700000002', 'Mariam TRAORE', 'suspended', 'motif de suspension renseigné'],
                ['+2250700000003', 'Yao KOFFI', 'dormant', 'sans véhicule'],
                ['+2250700000004', 'Fatoumata BAMBA', 'active', 'CGU non acceptées'],
                ['+2250700000005', 'Ibrahim DIALLO', 'active', 'sans yango_id'],
                ['+2250700000006', "Aya N'GUESSAN", 'active', 'OTP verrouillé'],
                ['+2250700000007', 'Seydou OUATTARA', 'active', 'sans véhicule'],
            ],
        );
    }

    /**
     * Crée ou met à jour un conducteur, et son véhicule le cas échéant.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $vehicle
     */
    private function driver(string $phone, array $attributes, ?array $vehicle = null): Driver
    {
        $driver = Driver::where('phone', $phone)->first();

        if ($driver === null) {
            $driver = Driver::factory()->create(array_merge(['phone' => $phone], $attributes));
        } else {
            $driver->forceFill($attributes)->save();
        }

        if ($vehicle !== null && ! $driver->vehicles()->where('plate_number', $vehicle['plate_number'])->exists()) {
            Vehicle::factory()->for($driver)->create($vehicle);
        }

        return $driver;
    }
}
