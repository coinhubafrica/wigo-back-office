<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeStatus;
use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Notifications\ChallengeTicketEarned;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Émission des tickets d'une tombola : ce qui est dû moins ce qui est détenu.
 *
 * Trois décisions, prises contre les trois défauts de la version précédente :
 *
 * 1. **Le compte se fait sur la période du challenge**, pas sur le cumul de
 *    carrière du conducteur. L'ancien calcul divisait
 *    `driver_daily_activities.orders_total` — un compteur à vie — par la
 *    tranche : un conducteur arrivant avec 149 courses au compteur gagnait un
 *    ticket à sa première course du challenge, et le compte affiché au mobile
 *    (`DriverProgressService::ticketing()`, lui période-scopé) le démentait.
 *
 * 2. **L'idempotence se compte, elle ne se date pas.** L'ancienne garde
 *    regardait s'il existait déjà un ticket de ce jour pour ce conducteur : la
 *    passe horaire rejouant la journée en cours, les courses arrivées après le
 *    premier mint ne donnaient plus jamais de ticket. Ici on mint la
 *    différence entre le dû et le détenu, et rejouer ne mint rien.
 *
 * 3. **Le rattrapage est possible.** Rien n'étant lié au jour synchronisé, un
 *    challenge démarré en cours de période se rattrape en une passe
 *    (`ChallengeLifecycleService::activateDue()`).
 *
 * Tout conducteur participe à toute tombola : il n'y a pas d'inscription, donc
 * pas de liste à filtrer. Le balayage part des courses.
 */
class ChallengeTicketMinter
{
    /**
     * Émet les tickets manquants d'un challenge, pour un conducteur ou pour
     * tous.
     *
     * `DrawPending` est exclu à dessein : le vivier est gelé, numéroté et
     * haché, et un ticket qui entrerait après coup n'aurait pas de
     * `range_number` — `DrawService::drawRaffle()` compte tous les tickets
     * puis cherche celui qui porte le numéro tiré, et échouerait.
     *
     * @return int nombre de tickets émis
     */
    public function mintFor(Challenge $challenge, ?Driver $driver = null): int
    {
        $ratio = (int) $challenge->trips_per_ticket;

        if (! $challenge->isTicketBasedRaffle() || $challenge->status !== ChallengeStatus::Active || $ratio <= 0) {
            return 0;
        }

        $minted = 0;

        foreach ($this->holdersOwedTickets($challenge, $ratio, $driver) as $row) {
            $minted += $this->mintForHolder($challenge, $ratio, (string) $row->driver_id);
        }

        return $minted;
    }

    /**
     * Émet les tickets des tombolas actives couvrant cette journée.
     *
     * Appelé après l'écriture du grand livre journalier d'un conducteur : la
     * journée dit *quels* challenges regarder, elle ne borne pas le compte.
     *
     * @return int nombre de tickets émis
     */
    public function mintForDriverOn(Driver $driver, CarbonInterface $date): int
    {
        $challenges = Challenge::query()
            ->where('is_ticket_based', true)
            ->where('status', ChallengeStatus::Active)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->get();

        $minted = 0;

        foreach ($challenges as $challenge) {
            $minted += $this->mintFor($challenge, $driver);
        }

        return $minted;
    }

    /**
     * Les porteurs à qui il manque au moins un ticket, en une requête.
     *
     * Le `whereRaw` compare en entiers (`orders >= (held + 1) * ratio`) plutôt
     * que de diviser : pas de `floor()`, donc rien qui s'écrive différemment
     * sur MySQL et sur SQLite. S'appuie sur
     * `yango_orders (status, completed_at, driver_id)` et
     * `challenge_tickets (challenge_id, driver_id)`.
     *
     * @return LazyCollection<int, object>
     */
    private function holdersOwedTickets(Challenge $challenge, int $ratio, ?Driver $driver)
    {
        $orders = YangoOrder::query()
            ->selectRaw('driver_id, count(*) as orders')
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
            ->when($driver !== null, fn ($query) => $query->where('driver_id', $driver->getKey()))
            ->groupBy('driver_id')
            ->havingRaw('count(*) >= ?', [$ratio]);

        $held = ChallengeTicket::query()
            ->selectRaw('driver_id, count(*) as held')
            ->where('challenge_id', $challenge->id)
            ->groupBy('driver_id');

        return DB::query()
            ->fromSub($orders, 'o')
            ->leftJoinSub($held, 't', 't.driver_id', '=', 'o.driver_id')
            ->selectRaw('o.driver_id')
            ->whereRaw('o.orders >= (coalesce(t.held, 0) + 1) * ?', [$ratio])
            ->orderBy('o.driver_id')
            ->cursor();
    }

    /**
     * Émet les tickets manquants d'un porteur, sous verrou.
     *
     * Deux verrous, deux rôles distincts :
     *
     * - `lockForUpdate` sur le conducteur **sérialise** les trois écrivains
     *   possibles (passe horaire, tirage mobile, `challenges:advance`). Un
     *   conducteur par transaction, jamais deux : pas d'interblocage croisé.
     * - `sharedLock` sur le challenge le fait **attendre le gel** :
     *   `DrawService::freezePool()` prend un verrou exclusif sur la même
     *   ligne, donc soit ce mint passe avant le gel, soit il lit `DrawPending`
     *   et s'arrête. Les mints ne se bloquent pas entre eux (verrou partagé).
     *
     * Reste la contrainte unique `(challenge, conducteur, sequence)` comme
     * garantie de dernier recours : sur SQLite, et partout où le verrou
     * n'aurait pas suffi, un doublon est refusé par la base plutôt qu'écrit.
     *
     * @return int nombre de tickets émis
     */
    private function mintForHolder(Challenge $challenge, int $ratio, string $driverId): int
    {
        /** @var array{driver: Driver, earned: int, held: int}|null $earned */
        $earned = DB::transaction(function () use ($challenge, $ratio, $driverId): ?array {
            $driver = Driver::query()->whereKey($driverId)->lockForUpdate()->first();

            if ($driver === null) {
                return null;
            }

            $locked = Challenge::query()->whereKey($challenge->id)->sharedLock()->first();

            if ($locked === null || $locked->status !== ChallengeStatus::Active) {
                return null;
            }

            $orders = YangoOrder::query()
                ->where('driver_id', $driver->getKey())
                ->where('status', YangoOrderStatus::Complete)
                ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
                ->count();

            $held = ChallengeTicket::query()
                ->where('challenge_id', $challenge->id)
                ->where('driver_id', $driver->getKey())
                ->count();

            $due = intdiv($orders, $ratio);
            $deficit = $due - $held;

            if ($deficit <= 0) {
                return null;
            }

            $rows = $this->rowsFor($challenge, $driver, $ratio, $held, $deficit);

            try {
                ChallengeTicket::query()->insert($rows);
            } catch (UniqueConstraintViolationException) {
                // Un autre écrivain a minté les mêmes rangs entre-temps : la
                // contrainte a fait son office, il n'y a rien à réparer.
                return null;
            }

            return ['driver' => $driver, 'earned' => count($rows), 'held' => $held + count($rows)];
        });

        if ($earned === null) {
            return 0;
        }

        // Après commit : la notification cite un compte de tickets, elle ne
        // doit pas partir avant qu'ils existent pour de bon.
        $earned['driver']->notify(new ChallengeTicketEarned($challenge, $earned['earned'], $earned['held']));

        return $earned['earned'];
    }

    /**
     * Les lignes à écrire, datées du jour de la course qui a franchi la
     * tranche.
     *
     * Le mobile présente `date` comme « le jour où le ticket a été gagné » :
     * dater du jour du mint afficherait « aujourd'hui » sur un rattrapage, et
     * l'ordre `(date, id)` du gel du vivier n'aurait plus de sens. On relit
     * donc les courses de la tranche concernée — au plus `deficit * ratio`
     * lignes, et un mint horaire n'en franchit qu'une.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFor(Challenge $challenge, Driver $driver, int $ratio, int $held, int $deficit): array
    {
        $dates = YangoOrder::query()
            ->where('driver_id', $driver->getKey())
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->offset($held * $ratio)
            ->limit($deficit * $ratio)
            ->pluck('completed_at');

        $rows = [];

        for ($step = 1; $step <= $deficit; $step++) {
            $crossing = $dates[$step * $ratio - 1] ?? null;

            $rows[] = [
                'id' => (string) Str::ulid(),
                'challenge_id' => $challenge->id,
                'driver_id' => $driver->getKey(),
                'sequence' => $held + $step,
                'date' => ($crossing ?? now())->toDateString(),
                'created_at' => now(),
            ];
        }

        return $rows;
    }
}
