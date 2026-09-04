<?php

use App\Enums\BackOfficeModule;
use App\Http\Resources\CnpsStatementPayload;
use App\Livewire\Cnps\Index;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Models\User;
use App\Services\Cnps\CnpsStatementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('a permitted user reaches the cnps page', function (): void {
    $driver = Driver::factory()->create(['last_name' => 'OUATTARA', 'first_name' => 'Seydou']);
    CnpsDeclaration::factory()->forPeriod('2026-08', 9000)->create(['driver_id' => $driver->id]);

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route(BackOfficeModule::Cnps->route()))
        ->assertOk()
        ->assertSee('Seydou OUATTARA');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(cnpsUser('stock'))
        ->get(route(BackOfficeModule::Cnps->route()))
        ->assertForbidden();
});

it('it shows the declared total against the reference', function (): void {
    $driver = Driver::factory()->create();
    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);

    $row = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->viewData('rows')
        ->first();

    $this->assertSame(6000, (int) $row->period_declared);
    $this->assertSame(9000, (int) $row->period_reference);
    $this->assertSame(2, (int) $row->period_payments);
});

it('a month is judged by the reference that applied then', function (): void {
    $driver = Driver::factory()->create();
    CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);
    CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-02', 6000)->create(['driver_id' => $driver->id]);

    $row = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->set('period', '2026-02')
        ->viewData('rows')
        ->first();

    // Février reste jugé à 6 000, malgré la hausse de mars.
    $this->assertSame(6000, (int) $row->period_reference);
});

it('the state filter separates paid partial and late', function (): void {
    $paid = Driver::factory()->create(['last_name' => 'PAYE']);
    $partial = Driver::factory()->create(['last_name' => 'PARTIEL']);
    $late = Driver::factory()->create(['last_name' => 'RETARD']);

    foreach ([$paid, $partial, $late] as $driver) {
        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
    }

    CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $paid->id]);
    CnpsDeclaration::factory()->forPeriod('2026-07', 4000)->create(['driver_id' => $partial->id]);

    $component = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->set('period', '2026-07');

    $names = fn (string $state): array => $component->call('filterByState', $state)
        ->viewData('rows')->pluck('last_name')->all();

    $this->assertSame(['PAYE'], $names('paid'));
    $this->assertSame(['PARTIEL'], $names('partial'));
    $this->assertSame(['RETARD'], $names('late'));
});

it('the current month lists undeclared drivers as pending not late', function (): void {
    $driver = Driver::factory()->create(['last_name' => 'SANSRIEN']);
    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);

    $component = Livewire::actingAs(cnpsUser('gestionnaire'))->test(Index::class);

    // Le mois en cours : rien n'est « en retard », le conducteur a le temps.
    $this->assertCount(0, $component->call('filterByState', 'late')->viewData('rows'));
    $this->assertCount(1, $component->call('filterByState', 'pending')->viewData('rows'));
});

it('search matches the name the phone and the yango account', function (): void {
    Driver::factory()->create(['last_name' => 'KOFFI', 'phone' => '+2250700000091', 'yango_id' => 'yango-driver-091']);
    Driver::factory()->create(['last_name' => 'TRAORE', 'phone' => '+2250700000092', 'yango_id' => 'yango-driver-092']);

    $component = Livewire::actingAs(cnpsUser('gestionnaire'))->test(Index::class);

    foreach (['koffi', '0700000091', 'driver-091'] as $term) {
        $rows = $component->set('search', $term)->viewData('rows');

        $this->assertCount(1, $rows, "recherche « {$term} »");
        $this->assertSame('KOFFI', $rows->first()->last_name);
    }
});

it('the header totals count the month', function (): void {
    $declaring = Driver::factory()->create();
    Driver::factory()->create();

    CnpsDeclaration::factory()->forPeriod('2026-07', 5000)->create(['driver_id' => $declaring->id]);
    CnpsDeclaration::factory()->forPeriod('2026-07', 4000)->create(['driver_id' => $declaring->id]);

    $totals = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->set('period', '2026-07')
        ->viewData('totals');

    $this->assertSame(9000, $totals['declared']);
    $this->assertSame(1, $totals['drivers_declaring']);
    // L'autre conducteur n'a rien déclaré sur un mois révolu.
    $this->assertSame(1, $totals['behind']);
});

it('a proof is flagged on the row', function (): void {
    $driver = Driver::factory()->create();
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->withProof('cnps-proofs/x/y.jpg')
        ->create(['driver_id' => $driver->id]);

    $row = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->viewData('rows')
        ->first();

    $this->assertSame(1, (int) $row->period_proofs);
});

it('the page says it does not validate anything', function (): void {
    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route(BackOfficeModule::Cnps->route()))
        ->assertOk()
        // Rien à approuver : la page le dit, pour qu'aucun agent ne
        // croie que son inaction bloque un versement.
        ->assertSee('Seuls les états de la CNPS font foi', false)
        ->assertDontSee('Valider')
        ->assertDontSee('Rejeter');
});

it('the module description does not promise a validation queue', function (): void {
    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route(BackOfficeModule::Cnps->route()))
        ->assertOk()
        ->assertDontSee('File de validation');
});

it('a deleted driver never appears even when filtering', function (): void {
    $kept = Driver::factory()->create(['last_name' => 'PRESENT']);
    $gone = Driver::factory()->create(['last_name' => 'SUPPRIME']);

    foreach ([$kept, $gone] as $driver) {
        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);
    }

    $gone->delete();

    $component = Livewire::actingAs(cnpsUser('gestionnaire'))
        ->test(Index::class)
        ->set('period', '2026-07');

    // Sans filtre comme avec : l'enveloppe ne doit pas rouvrir la porte
    // aux conducteurs supprimés.
    $this->assertSame(['PRESENT'], $component->viewData('rows')->pluck('last_name')->all());
    $this->assertSame(
        ['PRESENT'],
        $component->call('filterByState', 'paid')->viewData('rows')->pluck('last_name')->all(),
    );
});

// ------------------------------------------------- panneau fiche conducteur

it('the driver fiche shows the cnps panel', function (): void {
    $driver = Driver::factory()->create();
    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 6000)->create(['driver_id' => $driver->id]);

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertSee('Cotisations CNPS (RSTI)')
        // La carte du haut ne dit plus « — ».
        ->assertSee('6 000')
        ->assertSee('Partiel')
        ->assertSee('Août 2026');
});

it('the fiche panel lists the five previous months, not the mobile thirteen', function (): void {
    $driver = Driver::factory()->create();
    CnpsReference::factory()->effectiveFrom('2025-01', 9000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertSee('Juillet 2026')
        ->assertSee('Payé')
        // Le mois le plus ancien de la fenêtre : cinq mois avant août.
        ->assertSee('Mars 2026')
        ->assertSee('En retard')
        // La profondeur mobile n'est pas celle de la fiche : ce panneau
        // s'aligne sur ses voisins, il ne déroule pas l'année.
        ->assertDontSee('Août 2025');
});

it('the mobile statement still carries thirteen months', function (): void {
    $driver = Driver::factory()->create();
    CnpsReference::factory()->effectiveFrom('2025-01', 9000)->create(['driver_id' => $driver->id]);

    $statement = CnpsStatementPayload::build($driver, app(CnpsStatementService::class));

    // Le mois en cours plus douze d'historique.
    expect($statement['history'])->toHaveCount(12)
        ->and($statement['current']['label'])->toBe('Août 2026')
        ->and(end($statement['history'])['label'])->toBe('Août 2025');
});

it('the fiche panel holds no validation control', function (): void {
    $driver = Driver::factory()->create();
    CnpsDeclaration::factory()->forPeriod('2026-08', 6000)->create(['driver_id' => $driver->id]);

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertDontSee('Valider')
        ->assertDontSee('Rejeter');
});

it('a driver without a reference shows no invented amount', function (): void {
    $driver = Driver::factory()->create();

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertSee('Aucun montant fixé par le conducteur');
});

it('the fiche panel only shows that drivers declarations', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $mine->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 21000)->create(['driver_id' => $other->id]);

    $this->actingAs(cnpsUser('gestionnaire'))
        ->get(route('bo.drivers.show', $mine))
        ->assertOk()
        ->assertSee('3 000')
        ->assertDontSee('21 000');
});

function cnpsUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
