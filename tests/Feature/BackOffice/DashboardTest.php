<?php

/**
 * Tableau de bord : ce qu'il montre, à qui, et pour quelle semaine.
 *
 * Deux promesses à tenir. D'abord la cloison : un bloc dont l'agent n'a pas le
 * module ne doit pas paraître — il exposerait un agrégat interdit et mènerait
 * à un 403. Ensuite la période : les indicateurs de courses suivent la semaine
 * choisie, les autres restent au temps réel.
 */

use App\Livewire\Dashboard;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\SupportRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('shows the drivers and requests blocks to a gestionnaire', function (): void {
    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertSee(__('backoffice.dashboard.active_drivers'))
        ->assertSee(__('backoffice.dashboard.orders_week'))
        ->assertSee(__('backoffice.dashboard.open_requests'))
        ->assertSee(__('backoffice.dashboard.cnps_month'));
});

it('hides the recharges card from a role without the module', function (): void {
    // `gestionnaire` ne tient pas les paiements : ni la carte, ni son agrégat.
    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertDontSee(__('backoffice.dashboard.recharges_today'));
});

it('hides the orders charts from a role without the drivers module', function (): void {
    // Sans Chauffeurs, ni la courbe ni les barres : les courses sont un
    // agrégat du parc, et c'est ce module qui en porte le détail.
    Livewire::actingAs(dashboardUser('admin'))
        ->test(Dashboard::class)
        ->assertDontSee(__('backoffice.dashboard.trend_12_weeks'))
        ->assertDontSee(__('backoffice.dashboard.orders_week'));
});

it('counts only the orders of the selected week', function (): void {
    $driver = Driver::factory()->create();
    $thisWeek = CarbonImmutable::now()->startOfWeek();
    $lastWeek = $thisWeek->subWeek();

    DriverDailyActivity::factory()->for($driver)->create([
        'activity_date' => $thisWeek->format('Y-m-d'),
        'orders_completed' => 11,
    ]);
    DriverDailyActivity::factory()->for($driver)->create([
        'activity_date' => $lastWeek->format('Y-m-d'),
        'orders_completed' => 47,
    ]);

    // On lit la valeur de la carte « Courses de la semaine », pas la page :
    // « 47 » paraît aussi sur l'axe de la courbe, qui couvre douze semaines et
    // ne suit pas le sélecteur, et « 11 » se retrouve dans des noms de classe.
    $weekTotal = static function (string $html): string {
        $card = Str::of($html)
            ->after(__('backoffice.dashboard.orders_week'))
            ->before(__('backoffice.dashboard.recharges_today'))
            ->toString();

        preg_match('/text-3xl.*?<span[^>]*>\s*([\d\s\x{202F}]+)/su', $card, $matches);

        return trim($matches[1] ?? '');
    };

    $component = Livewire::actingAs(dashboardUser('direction'))->test(Dashboard::class);

    // Par défaut, la semaine en cours.
    expect($weekTotal($component->html()))->toBe('11');

    // La semaine précédente change le total, et elle seule.
    $component->set('week', $lastWeek->format('o-\WW'));

    expect($weekTotal($component->html()))->toBe('47');
});

it('marks the current week as in progress and a closed week as closed', function (): void {
    $lastWeek = CarbonImmutable::now()->startOfWeek()->subWeek();

    Livewire::actingAs(dashboardUser('direction'))
        ->test(Dashboard::class)
        ->assertSee(__('backoffice.dashboard.week_in_progress_notice'))
        ->set('week', $lastWeek->format('o-\WW'))
        ->assertSee(__('backoffice.dashboard.week_closed_notice'))
        ->assertDontSee(__('backoffice.dashboard.week_in_progress_notice'));
});

it('falls back to the current week when the url carries an unusable value', function (): void {
    // `week` est un paramètre d'URL : il se tape à la main, et une valeur
    // illisible ne doit pas casser l'écran.
    Livewire::actingAs(dashboardUser('direction'))
        ->test(Dashboard::class, ['week' => 'pas-une-semaine'])
        ->assertOk()
        ->assertSee(__('backoffice.dashboard.week_in_progress_notice'));
});

it('raises an alert for a request past its sla', function (): void {
    SupportRequest::factory()->breached()->create();

    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertSee(__('backoffice.dashboard.alerts'))
        ->assertDontSee(__('backoffice.dashboard.no_alerts'));
});

it('shows nothing to report when the queue is clean', function (): void {
    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertSee(__('backoffice.dashboard.no_alerts'));
});

it('shows the empty state for the request table when nothing is open', function (): void {
    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertSee(__('backoffice.dashboard.no_open_requests'));
});

it('lists an open request with its driver', function (): void {
    // Le ticket suit le conducteur de sa conversation : `driver_id` est
    // dénormalisé et se déduit d'elle, le poser directement ne suffit pas.
    $driver = Driver::factory()->create(['first_name' => 'Souleymane', 'last_name' => 'DIABATÉ']);
    $conversation = Conversation::factory()->for($driver)->create();
    SupportRequest::factory()->forConversation($conversation)->create();

    Livewire::actingAs(dashboardUser('gestionnaire'))
        ->test(Dashboard::class)
        ->assertSee('Souleymane DIABATÉ')
        ->assertDontSee(__('backoffice.dashboard.no_open_requests'));
});

function dashboardUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
