<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CnpsReferenceSetter;
use App\Enums\DriverStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CnpsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.v1.cnps.show'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('api.unauthenticated'));
    }

    public function test_it_returns_the_envelope_with_reference_current_and_history(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.cnps.show'))
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['reference', 'current', 'history'],
            ]);
    }

    public function test_the_current_month_reproduces_the_summary_card(): void
    {
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
    }

    public function test_the_history_covers_twelve_months_newest_first_without_the_current_one(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $history = $this->getJson(route('api.v1.cnps.show'))->assertOk()->json('data.history');

        $this->assertCount(12, $history);
        $this->assertSame('2026-07', $history[0]['period']);
        $this->assertSame('Juillet 2026', $history[0]['label']);
        $this->assertSame('2025-08', $history[11]['period']);
        $this->assertNotContains('2026-08', array_column($history, 'period'));
    }

    public function test_a_fully_paid_month_reads_as_paid(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);

        $this->getJson(route('api.v1.cnps.show'))
            ->assertOk()
            ->assertJsonPath('data.history.0.status', 'paid')
            ->assertJsonPath('data.history.0.declared_amount', 9000)
            ->assertJsonPath('data.history.0.remaining', 0);
    }

    public function test_an_undeclared_past_month_reads_as_late_with_nothing_declared(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        CnpsReference::factory()->effectiveFrom('2025-01', 9000)->create(['driver_id' => $driver->id]);

        $history = $this->getJson(route('api.v1.cnps.show'))->assertOk()->json('data.history');
        $november = collect($history)->firstWhere('period', '2025-11');

        $this->assertSame('late', $november['status']);
        $this->assertSame(0, $november['declared_amount']);
        $this->assertSame('Novembre 2025', $november['label']);
        $this->assertSame([], $november['declarations']);
    }

    public function test_the_current_month_is_pending_not_late_when_nothing_is_declared(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);

        $this->getJson(route('api.v1.cnps.show'))
            ->assertOk()
            ->assertJsonPath('data.current.status', 'pending');
    }

    public function test_raising_the_reference_does_not_rewrite_past_months(): void
    {
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
    }

    public function test_a_driver_who_never_set_a_reference_gets_null_rather_than_a_default(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.cnps.show'))
            ->assertOk()
            ->assertJsonPath('data.reference', null)
            ->assertJsonPath('data.current.reference_amount', null)
            ->assertJsonPath('data.current.remaining', 0)
            ->assertJsonPath('data.current.progress', 0);
    }

    public function test_declarations_expose_whether_a_proof_is_attached(): void
    {
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
    }

    public function test_a_driver_only_ever_sees_their_own_statement(): void
    {
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
    }

    public function test_a_suspended_driver_still_reads_their_statement(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents non conformes',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson(route('api.v1.cnps.show'))->assertOk();
    }

    // ---------------------------------------------------------------- écriture

    public function test_declaring_a_payment_records_it(): void
    {
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
    }

    public function test_a_month_accepts_several_declarations_and_they_add_up(): void
    {
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
    }

    public function test_it_rejects_a_future_month_a_future_payment_and_a_stale_one(): void
    {
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
    }

    public function test_it_rejects_a_malformed_month(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        foreach (['2026-13', 'aout', '2026-8'] as $period) {
            $this->postJson(route('api.v1.cnps.declarations.store'), [
                'period' => $period, 'amount' => 3000, 'payment_date' => '2026-08-12',
            ])->assertStatus(422)->assertJsonValidationErrors('period');
        }
    }

    public function test_a_proof_is_stored_on_the_private_disk(): void
    {
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
    }

    public function test_it_rejects_an_oversized_or_disguised_proof(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->post(route('api.v1.cnps.declarations.store'), [
            'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
            'proof' => UploadedFile::fake()->create('gros.jpg', 6000, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('proof');

    }

    public function test_it_rejects_a_text_file_disguised_as_an_image(): void
    {
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
    }

    public function test_setting_the_reference_adds_a_row_and_keeps_the_old_one(): void
    {
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
    }

    public function test_the_reference_stays_within_the_rsti_bounds(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 3599])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 21601])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 3600])->assertOk();
        $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 21600])->assertOk();
    }

    public function test_a_suspended_driver_cannot_declare_or_change_the_reference(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents non conformes',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.cnps.declarations.store'), [
            'period' => '2026-08', 'amount' => 3000, 'payment_date' => '2026-08-12',
        ])->assertForbidden();

        $this->putJson(route('api.v1.cnps.reference.update'), ['amount' => 9000])->assertForbidden();
    }

    // ------------------------------------------------------------ justificatif

    public function test_a_signed_url_streams_the_proof_to_its_owner(): void
    {
        Storage::fake('local');

        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $path = UploadedFile::fake()->image('wave.jpg')->store("cnps-proofs/{$driver->id}", 'local');
        $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
            ->create(['driver_id' => $driver->id, 'proof_path' => $path]);

        $this->get($this->proofUrl($declaration))->assertOk();
    }

    public function test_another_driver_cannot_read_a_proof_even_with_a_valid_signature(): void
    {
        Storage::fake('local');

        $owner = Driver::factory()->create();
        $path = UploadedFile::fake()->image('wave.jpg')->store("cnps-proofs/{$owner->id}", 'local');
        $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
            ->create(['driver_id' => $owner->id, 'proof_path' => $path]);

        // Signature valide, mais délivrée pour la déclaration d'un autre : la
        // signature n'est pas une autorisation.
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson($this->proofUrl($declaration))->assertForbidden();
    }

    public function test_an_unsigned_proof_url_is_refused(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
            ->create(['driver_id' => $driver->id, 'proof_path' => 'cnps-proofs/x.jpg']);

        $this->getJson(route('api.v1.cnps.declarations.proof', $declaration))
            ->assertForbidden();
    }

    public function test_a_declaration_without_a_proof_returns_not_found(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $declaration = CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
            ->create(['driver_id' => $driver->id]);

        $this->getJson($this->proofUrl($declaration))->assertNotFound();
    }

    private function proofUrl(CnpsDeclaration $declaration): string
    {
        return URL::temporarySignedRoute(
            'api.v1.cnps.declarations.proof',
            now()->addMinutes(5),
            ['declaration' => $declaration->id],
        );
    }
}
