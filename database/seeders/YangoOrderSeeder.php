<?php

namespace Database\Seeders;

use App\Enums\YangoOrderStatus;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Courses terminées de développement, réparties sur plusieurs jours distincts
 * (pas toutes le même jour) : le grand livre journalier des tickets a besoin
 * d'un vrai étalement jour par jour pour être exercé de façon réaliste.
 *
 * Idempotent par (driver_id, yango_id) : rejouable sans dupliquer les lignes.
 */
class YangoOrderSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(DailyActivityService::class);

        /** @var array<int, Driver> $drivers */
        $drivers = Driver::query()->get()->all();

        if ($drivers === []) {
            $this->command->warn('Aucun conducteur trouvé : exécutez DriverSeeder avant YangoOrderSeeder.');

            return;
        }

        // Chaque conducteur reçoit un nombre différent de courses par jour sur
        // 6 jours, pour produire des niveaux d'activité variés (certains
        // franchissent des seuils de tickets, d'autres non).
        $days = collect(range(0, 5))->map(fn (int $offset) => Carbon::now()->subDays(6 - $offset)->startOfDay());

        foreach ($drivers as $driverIndex => $driver) {
            $ordersPerDay = 10 + ($driverIndex * 7); // 10, 17, 24, 31... courses/jour, sur 6 jours (60, 102, 144...)

            foreach ($days as $day) {
                for ($i = 0; $i < $ordersPerDay; $i++) {
                    $yangoId = "order-{$driver->id}-{$day->toDateString()}-{$i}";

                    YangoOrder::query()->firstOrCreate(
                        ['driver_id' => $driver->id, 'yango_id' => $yangoId],
                        [
                            'status' => YangoOrderStatus::Complete,
                            'week_iso' => $day->format('o-\WW'),
                            'completed_at' => $day->copy()->addHours(random_int(6, 22)),
                        ],
                    );
                }

                $service->recordDay($driver, $day);
            }
        }

        $this->command->info('YangoOrderSeeder : courses générées sur 6 jours pour '.count($drivers).' conducteur(s).');
    }
}
