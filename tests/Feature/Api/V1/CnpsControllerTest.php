<?php

use App\Enums\CnpsReferenceSetter;
use App\Enums\DriverStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.cnps.show'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.unauthenticated'));
});

it('returns the envelope with reference current and history', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => ['reference', 'current', 'history'],
        ]);
});

it('reproduces the summary card for the current month', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
    // Août réglé en deux fois : 3 000 + 3 000 sur 9 000 attendus.
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);

    $response = $this->getJson(route('api.v1.cnps.show'))->assertOk();

    $response->assertJsonPath('data.reference.amount', 9000);
    $response->assertJsonPath('data.current.period', '2026-08');
    $response->assertJsonPath('data.current.label', 'Août 2026');
    $response->assertJsonPath('data.current.declared_amount', 6000);
    $response->assertJsonPath('data.current.remaining', 3000);
    $response->assertJsonPath('data.current.progress', 67);
    $response->assertJsonPath('data.current.status', 'partial');
    $response->assertJsonCount(2, 'data.current.declarations');
});

it('covers twelve months newest first without the current one in the history', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $history = $this->getJson(route('api.v1.cnps.show'))->assertOk()->json('data.history');

    $this->assertCount(12, $history);
    $this->assertSame('2026-07', $history[0]['period']);
    $this->assertSame('Juillet 2026', $history[0]['label']);
    $this->assertSame('2025-08', $history[11]['period']);
    $this->assertNotContains('2026-08', array_column($history, 'period'));
});

it('reads a fully paid month as paid', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);

    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.history.0.status', 'paid')
        ->assertJsonPath('data.history.0.declared_amount', 9000)
        ->assertJsonPath('data.history.0.remaining', 0);
});

it('reads an undeclared past month as late with nothing declared', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2025-01', 9000)->create(['driver_id' => $driver->id]);

    $history = $this->getJson(route('api.v1.cnps.show'))->assertOk()->json('data.history');
    $november = collect($history)->firstWhere('period', '2025-11');

    $this->assertSame('late', $november['status']);
    $this->assertSame(0, $november['declared_amount']);
    $this->assertSame('Novembre 2025', $november['label']);
    $this->assertSame([], $november['declarations']);
});

it('reads the current month as pending not late when nothing is declared', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);

    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.current.status', 'pending');
});

it('does not rewrite past months when raising the reference', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);
    CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);
    // 6 000 soldait février, mais ne solderait pas mars.
    CnpsDeclaration::factory()->forPeriod('2026-02', 6000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-03', 6000)->create(['driver_id' => $driver->id]);

    $history = collect($this->getJson(route('api.v1.cnps.show'))->assertOk()->json('data.history'))
        ->keyBy('period');

    $this->assertSame(6000, $history['2026-02']['reference_amount']);
    $this->assertSame('paid', $history['2026-02']['status']);
    $this->assertSame(9000, $history['2026-03']['reference_amount']);
    $this->assertSame('partial', $history['2026-03']['status']);
});

it('gives a driver who never set a reference null rather than a default', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.reference', null)
        ->assertJsonPath('data.current.reference_amount', null)
        ->assertJsonPath('data.current.remaining', 0)
        ->assertJsonPath('data.current.progress', 0);
});

it('exposes whether a proof is attached on declarations', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->withProof('cnps-proofs/x/y.jpg')
        ->create(['driver_id' => $driver->id]);

    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.current.declarations.0.has_proof', true)
        // Le chemin de stockage ne doit jamais transiter sur le fil.
        ->assertJsonMissingPath('data.current.declarations.0.proof_path');
});

it('only ever shows a driver their own statement', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $mine->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 9000)->create(['driver_id' => $other->id]);

    Sanctum::actingAs($mine, ['mobile:*']);
    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.current.declared_amount', 3000);

    Sanctum::actingAs($other, ['mobile:*']);
    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.current.declared_amount', 9000);
});

it('lets a suspended driver still read their statement', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents non conformes',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.cnps.show'))->assertOk();
});

// ---------------------------------------------------------------- écriture

it('records a declared payment', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08',
        'amount' => 3000,
        'payment_date' => '2026-08-12',
    ])
        ->assertCreated()
        ->assertJsonPath('message', __('api.cnps.declaration_recorded'))
        ->assertJsonPath('data.period', '2026-08')
        ->assertJsonPath('data.declared_amount', 3000)
        ->assertJsonPath('data.has_proof', false);

    $this->assertDatabaseHas('cnps_declarations', [
        'driver_id' => $driver->id,
        'period' => '2026-08',
        'declared_amount' => 3000,
    ]);
});

it('accepts several declarations for a month and they add up', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);

    foreach ([3000, 3000] as $amount) {
        $this->postJson(route('api.v1.cnps.declarations.store'), [
            'period' => '2026-08',
            'amount' => $amount,
            'payment_date' => '2026-08-12',
        ])->assertCreated();
    }

    // Pas de 409 : déclarer plusieurs versements pour un mois est le cas
    // nominal, pas un doublon.
    $this->getJson(route('api.v1.cnps.show'))
        ->assertOk()
        ->assertJsonPath('data.current.declared_amount', 6000)
        ->assertJsonPath('data.current.status', 'partial');
});

it('rejects a future month a future payment and a stale one', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-09', 'amount' => 3000, 'payment_date' => '2026-08-12',
    ])->assertStatus(422)->assertJsonValidationErrors('period');

    $this->postJson(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-09-30',
    ])->assertStatus(422)->assertJsonValidationErrors('payment_date');

    // Au-delà de deux ans : année mal saisie plutôt qu'arriéré réel.
    $this->postJson(route('api.v1.cnps.declarations.store'), [
        'period' => '2016-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
    ])->assertStatus(422)->assertJsonValidationErrors('period');
});

it('rejects a malformed month', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    foreach (['2026-13', 'aout', '2026-8'] as $period) {
        $this->postJson(route('api.v1.cnps.declarations.store'), [
            'period' => $period, 'amount' => 3000, 'payment_date' => '2026-08-12',
        ])->assertStatus(422)->assertJsonValidationErrors('period');
    }
});

it('stores a proof on the private disk', function (): void {
    Storage::fake('local');

    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->post(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08',
        'amount' => 3000,
        'payment_date' => '2026-08-12',
        'proof' => UploadedFile::fake()->image('wave.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.has_proof', true);

    $path = CnpsDeclaration::query()->where('driver_id', $driver->id)->value('proof_path');

    $this->assertNotNull($path);
    $this->assertStringStartsWith("cnps-proofs/{$driver->id}/", $path);
    Storage::disk('local')->assertExists($path);
    // Jamais sur le disque public : ces pièces nomment une personne.
    Storage::disk('public')->assertMissing($path);
});

it('rejects an oversized or disguised proof', function (): void {
    Storage::fake('local');
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->post(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
        'proof' => UploadedFile::fake()->create('gros.jpg', 6000, 'image/jpeg'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('proof');

});

it('rejects a text file disguised as an image', function (): void {
    Storage::fake('local');
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    // `UploadedFile::fake()` marque le fichier comme « de test », ce qui
    // fait confiance au type déclaré : impossible d'y éprouver la détection.
    // Il faut un vrai fichier pour que `mimes` lise le contenu.
    $path = tempnam(sys_get_temp_dir(), 'faux').'.png';
    file_put_contents($path, 'ceci est du texte, pas une image');

    $this->post(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
        'proof' => new UploadedFile($path, 'faux.png', 'image/png', null, true),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('proof');

    @unlink($path);
});

it('adds a row and keeps the old one when setting the reference', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);

    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 9000])
        ->assertOk()
        ->assertJsonPath('data.amount', 9000)
        ->assertJsonPath('data.set_by', CnpsReferenceSetter::Driver->value);

    // L'ancien montant survit : c'est lui qui juge les mois passés.
    $this->assertSame(2, CnpsReference::query()->where('driver_id', $driver->id)->count());
    $this->assertDatabaseHas('cnps_references', ['driver_id' => $driver->id, 'amount' => 6000]);
});

it('keeps the reference within the rsti bounds', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 3599])
        ->assertStatus(422)->assertJsonValidationErrors('amount');

    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 21601])
        ->assertStatus(422)->assertJsonValidationErrors('amount');

    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 3600])->assertOk();
    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 21600])->assertOk();
});

it('prevents a suspended driver from declaring or changing the reference', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents non conformes',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.cnps.declarations.store'), [
        'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
    ])->assertForbidden();

    $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 9000])->assertForbidden();
});

// ------------------------------------------------------------ justificatif

it('streams the proof to its owner through a signed url', function (): void {
    Storage::fake('local');

    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $path = UploadedFile::fake()->image('wave.jpg')->store("cnps-proofs/{$driver->id}", 'local');
    $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->create(['driver_id' => $driver->id, 'proof_path' => $path]);

    $this->get(proofUrl($declaration))->assertOk();
});

it('prevents another driver from reading a proof even with a valid signature', function (): void {
    Storage::fake('local');

    $owner = Driver::factory()->create();
    $path = UploadedFile::fake()->image('wave.jpg')->store("cnps-proofs/{$owner->id}", 'local');
    $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->create(['driver_id' => $owner->id, 'proof_path' => $path]);

    // Signature valide, mais délivrée pour la déclaration d'un autre : la
    // signature n'est pas une autorisation.
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(proofUrl($declaration))->assertForbidden();
});

it('refuses an unsigned proof url', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->create(['driver_id' => $driver->id, 'proof_path' => 'cnps-proofs/x.jpg']);

    $this->getJson(route('api.v1.cnps.declarations.proof', $declaration))
        ->assertForbidden();
});

it('returns not found for a declaration without a proof', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
        ->create(['driver_id' => $driver->id]);

    $this->getJson(proofUrl($declaration))->assertNotFound();
});

function proofUrl(CnpsDeclaration $declaration): string
{
    return URL::temporarySignedRoute(
        'api.v1.cnps.declarations.proof',
        now()->addMinutes(5),
        ['declaration' => $declaration->id],
    );
}
