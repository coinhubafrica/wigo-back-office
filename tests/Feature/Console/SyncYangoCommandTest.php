<?php

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Jobs\SyncYangoJob;
use App\Models\Driver;
use App\Services\Yango\FakeYangoDirectory;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    /** @var FakeYangoDirectory $directory */
    $directory = app(YangoDirectory::class);
    $this->directory = $directory;
});

it('prints what the pass reconciled', function (): void {
    $this->directory->setDrivers([[
        'driver_profile' => [
            'id' => 'YAN-001',
            'first_name' => 'Kouassi',
            'last_name' => 'KONE',
            'phones' => ['+2250700000001'],
        ],
        'car' => ['id' => 'CAR-001', 'number' => '1234-AB-01'],
    ]]);

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('conducteurs : 1 sync')
        ->expectsOutputToContain('véhicules : 1 sync')
        ->assertSuccessful();

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('warns about records Yango no longer reports', function (): void {
    Driver::factory()->withYangoId('YAN-999')->staleSync(9)->create();

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('non remontés : 1 conducteurs')
        ->assertSuccessful();
});

it('fails when Yango refuses the pass', function (): void {
    $this->directory->failWith(new YangoFleetException('Clé invalide'));

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('Yango Fleet a refusé la synchronisation')
        ->assertFailed();
});

it('queues the pass instead of running it', function (): void {
    Queue::fake();

    $this->directory->setDrivers([[
        'driver_profile' => [
            'id' => 'YAN-001',
            'phones' => ['+2250700000001'],
        ],
    ]]);

    $this->artisan('yango:sync')
        ->expectsOutputToContain('mise en file')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoJob::class);

    // Sans `--now`, la commande ne synchronise rien elle-même : elle poste.
    $this->assertSame(0, Driver::query()->count());
});
