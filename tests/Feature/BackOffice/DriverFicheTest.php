<?php

use App\Enums\DriverStatus;
use App\Enums\ShopOrderStatus;
use App\Enums\SupportRequestStatus;
use App\Enums\TransactionType;
use App\Livewire\Drivers\Show;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\ShopOrder;
use App\Models\SupportRequest;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the driver fiche', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertSee('COMBA');
});

it('a user without the permission gets 403 on the fiche', function (): void {
    $driver = Driver::factory()->create();

    $this->actingAs(driverFicheUser('stock'))
        ->get(route('bo.drivers.show', $driver))
        ->assertForbidden();
});

it('the fiche shows the photo when the driver has one', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee(route('bo.drivers.photo', $driver), escape: false);
});

it('the fiche falls back to the initials without a photo', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA', 'photo_url' => null]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertDontSee(route('bo.drivers.photo', $driver), escape: false)
        ->assertSee('AC');
});

it('the fiche offers no photo moderation control', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertDontSee('approvePhoto')
        ->assertDontSee('rejectPhoto');
});

it('the photo route streams the file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('driver-photos/selfie.jpg', 'binaire');

    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertOk();
});

it('the photo route refuses a driver without a photo, saying nothing more', function (): void {
    // Même réponse qu'un identifiant inconnu : 404 aurait dit « ce conducteur
    // existe, mais sans portrait ».
    $driver = Driver::factory()->create(['photo_url' => null]);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertForbidden();
});

it('answers an unknown driver id exactly like a driver without a photo', function (): void {
    /*
     * Le code de statut ne doit pas permettre d'énumérer les conducteurs : un
     * identifiant inconnu et un conducteur sans portrait répondent tous deux
     * 403, avec le même corps.
     */
    $agent = driverFicheUser('direction');
    $withoutPhoto = Driver::factory()->create(['photo_url' => null]);

    $unknown = $this->actingAs($agent)
        ->get(route('bo.drivers.photo', '01jzzzzzzzzzzzzzzzzzzzzzzz'));

    $known = $this->actingAs($agent)
        ->get(route('bo.drivers.photo', $withoutPhoto));

    $unknown->assertForbidden();
    $known->assertForbidden();

    expect($unknown->getContent())->toBe($known->getContent());
});

it('the photo route 404s only once the request is authorised', function (): void {
    // Le portrait est référencé mais absent du disque : anomalie de stockage,
    // et l'accès est déjà accordé — la dire ne révèle rien.
    Storage::fake('local');

    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/disparue.jpg']);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertNotFound();
});

it('opens the photo to a support agent for the thread avatars', function (): void {
    // Le fil du support pointe ici pour ses avatars : borner la route aux
    // seuls Conducteurs cassait l'image d'un agent qui ne fait que du support.
    Storage::fake('local');
    Storage::disk('local')->put('driver-photos/selfie.jpg', 'binaire');

    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    $this->actingAs(driverFicheUser('stock'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertOk();
});

it('the photo route is closed to a role with neither drivers nor support', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    // `admin` n'a ni `module.drivers` ni `module.support-requests`.
    $this->actingAs(driverFicheUser('admin'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertForbidden();
});

it('suspending a driver requires a reason', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->set('showSuspendForm', true)
        ->set('suspensionReason', '')
        ->call('suspend')
        ->assertHasErrors(['suspensionReason' => 'required']);

    $this->assertSame(DriverStatus::Active, $driver->fresh()->status);
});

it('suspending a driver sets the status and reason', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->set('showSuspendForm', true)
        ->set('suspensionReason', 'Documents expirés')
        ->call('suspend');

    $driver->refresh();
    $this->assertSame(DriverStatus::Suspended, $driver->status);
    $this->assertSame('Documents expirés', $driver->suspension_reason);
});

it('reactivating a suspended driver clears the reason', function (): void {
    $driver = Driver::factory()->suspended('Documents non conformes')->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->call('confirmReactivate')
        ->assertSet('confirmingReactivation', true)
        ->call('reactivate')
        ->assertSet('confirmingReactivation', false);

    $driver->refresh();
    $this->assertSame(DriverStatus::Active, $driver->status);
    $this->assertNull($driver->suspension_reason);
});

it('cancelling the reactivation leaves the driver suspended', function (): void {
    $driver = Driver::factory()->suspended('Documents non conformes')->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->call('confirmReactivate')
        ->call('cancelReactivate')
        ->assertSet('confirmingReactivation', false);

    $driver->refresh();
    $this->assertSame(DriverStatus::Suspended, $driver->status);
    $this->assertSame('Documents non conformes', $driver->suspension_reason);
});

it('the fiche offers no account activation control beyond suspension', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Dormant]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertDontSee('Activer le compte')
        ->assertDontSee('Désactiver le compte');
});

it('the fiche shows all four activity panels at once', function (): void {
    $driver = Driver::factory()->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSeeInOrder([
            'Requêtes',
            'Commandes boutique',
            'Recharges Yango',
            'Cotisations CNPS (RSTI)',
        ]);
});

it('each empty panel states its own emptiness', function (): void {
    $driver = Driver::factory()->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee('Aucune requête de ce conducteur.')
        ->assertSee('Aucune commande de pièces.')
        ->assertSee('Aucune recharge enregistrée.');
});

it('the requests panel lists only this driver\'s requests', function (): void {
    $driver = Driver::factory()->create();
    $other = Driver::factory()->create();

    SupportRequest::factory()
        ->forConversation(Conversation::factory()->for($driver)->create())
        ->create(['number' => 4242, 'subject' => 'Changement de véhicule']);
    SupportRequest::factory()
        ->forConversation(Conversation::factory()->for($other)->create())
        ->create(['number' => 9999, 'subject' => 'Requête de quelqu\'un d\'autre']);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee('#4242')
        ->assertSee('Changement de véhicule')
        ->assertDontSee('#9999');
});

it('the open requests card counts only live requests', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->for($driver)->create();

    SupportRequest::factory()->forConversation($conversation)->create(['status' => SupportRequestStatus::Open]);
    SupportRequest::factory()->forConversation($conversation)->create(['status' => SupportRequestStatus::Pending]);
    SupportRequest::factory()->forConversation($conversation)->create(['status' => SupportRequestStatus::Resolved]);
    SupportRequest::factory()->forConversation($conversation)->create(['status' => SupportRequestStatus::Closed]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertViewHas('openRequestCount', 2);
});

it('the orders panel lists the shop orders', function (): void {
    $driver = Driver::factory()->create();
    ShopOrder::factory()->for($driver)->create([
        'reference' => 'CMD-2026-0117',
        'total_amount' => 24500,
        'status' => ShopOrderStatus::Delivered,
    ]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee('CMD-2026-0117')
        ->assertSee('24 500 FCFA');
});

it('the topups panel lists recharges and leaves other transaction types out', function (): void {
    $driver = Driver::factory()->create();
    Transaction::factory()->for($driver)->credited()->create([
        'reference' => 'RCH-2026-0042',
        'amount' => 5000,
    ]);
    Transaction::factory()->for($driver)->ofType(TransactionType::BonusPayout)->create([
        'reference' => 'BON-2026-0001',
    ]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee('RCH-2026-0042')
        ->assertSee('5 000 FCFA')
        ->assertDontSee('BON-2026-0001');
});

it('the cnps panel shows the statement and no validation control', function (): void {
    $driver = Driver::factory()->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee('Cotisations CNPS (RSTI)')
        ->assertDontSee('Valider')
        ->assertDontSee('Rejeter');
});

it('each panel is capped at five rows and points at its module', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->for($driver)->create();

    foreach (range(1, 7) as $index) {
        SupportRequest::factory()->forConversation($conversation)->create([
            'number' => 7000 + $index,
            'subject' => "Requête numéro {$index}",
            'created_at' => now()->subDays($index),
        ]);
        ShopOrder::factory()->for($driver)->create([
            'reference' => sprintf('CMD-2026-%04d', $index),
            'ordered_at' => now()->subDays($index),
        ]);
    }

    $rendered = Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver]);

    // Les cinq plus récents, pas les sept.
    $rendered->assertViewHas('requests', fn ($rows): bool => $rows->count() === 5)
        ->assertViewHas('orders', fn ($rows): bool => $rows->count() === 5)
        ->assertSee('#7001')
        ->assertDontSee('#7007')
        ->assertSee(route('bo.support-requests'), escape: false)
        ->assertSee(route('bo.shop-orders'), escape: false)
        ->assertSee(route('bo.recharges'), escape: false);
});

function driverFicheUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
