<?php

use App\Enums\DriverStatus;
use App\Enums\FulfilmentMode;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('local');

    $this->driver = Driver::factory()->create();
    Sanctum::actingAs($this->driver, ['mobile:*']);

    $this->product = Product::factory()->create(['unit_price' => 45000]);
    $this->pickupPoint = PickupPoint::factory()->create();
});

it('attaches both photos of the carte grise to the order', function (): void {
    $response = shopDocumentOrder()->assertCreated();

    $order = ShopOrder::query()->sole();

    $this->assertSame(2, $order->documents()->count());

    foreach ($order->documents as $document) {
        $this->assertSame('local', $document->disk);
        $this->assertSame($this->driver->id, $document->uploaded_by_driver_id);
        Storage::disk('local')->assertExists($document->path);
    }

    // La commande rendue porte les deux photos, chacune derrière une URL
    // signée — et jamais son chemin de stockage.
    $this->assertCount(2, $response->json('data.documents'));
    $this->assertStringContainsString('signature=', (string) $response->json('data.documents.0.url'));
    $this->assertStringNotContainsString(
        $order->documents->first()->path,
        (string) $response->json('data.documents.0.url'),
    );
});

it('refuses an order without the carte grise', function (): void {
    $payload = shopDocumentPayload();
    unset($payload['documents']);

    shopDocumentPost($payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');

    $this->assertSame(0, ShopOrder::query()->count());
});

it('refuses an order carrying only one photo', function (): void {
    shopDocumentPost([
        ...shopDocumentPayload(),
        'documents' => [UploadedFile::fake()->image('cg-1.jpg')],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');

    $this->assertSame(0, ShopOrder::query()->count());
});

it('refuses an order carrying more than two photos', function (): void {
    shopDocumentPost([
        ...shopDocumentPayload(),
        'documents' => [
            UploadedFile::fake()->image('cg-1.jpg'),
            UploadedFile::fake()->image('cg-2.jpg'),
            UploadedFile::fake()->image('cg-3.jpg'),
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');

    $this->assertSame(0, ShopOrder::query()->count());
});

it('refuses a photo that is not an image', function (): void {
    shopDocumentPost([
        ...shopDocumentPayload(),
        'documents' => [
            UploadedFile::fake()->image('cg-1.jpg'),
            UploadedFile::fake()->create('cg.pdf', 100, 'application/pdf'),
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents.1');

    $this->assertSame(0, ShopOrder::query()->count());
});

it('writes nothing when the order is refused', function (): void {
    $closed = Product::factory()->inactive()->create();

    shopDocumentPost([
        ...shopDocumentPayload(),
        'lines' => [['product_id' => $closed->id, 'qty' => 1]],
    ])->assertUnprocessable();

    // La référence fermée annule tout : ni commande, ni ligne de carte grise.
    $this->assertSame(0, ShopOrder::query()->count());
    $this->assertSame(0, ShopOrderDocument::query()->count());
});

it('prevents a suspended driver from ordering with documents', function (): void {
    Sanctum::actingAs(Driver::factory()->create(['status' => DriverStatus::Suspended]), ['mobile:*']);

    shopDocumentOrder()->assertForbidden();

    $this->assertSame(0, ShopOrderDocument::query()->count());
});

it('serves a photo only through a signed url', function (): void {
    shopDocumentOrder()->assertCreated();
    $document = ShopOrderDocument::query()->first();

    // Sans signature : rien ne sort du disque privé.
    $this->get(route('api.v1.shop.orders.documents.show', ['document' => $document->id]))
        ->assertForbidden();

    $this->get(shopDocumentSignedUrl($document))->assertOk();
});

it('refuses the signed url of another driver photo', function (): void {
    shopDocumentOrder()->assertCreated();
    $document = ShopOrderDocument::query()->first();

    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    // L'URL est signée et pourtant refusée : la signature n'est pas une
    // autorisation, le conducteur doit être le déposant.
    $this->get(shopDocumentSignedUrl($document))->assertForbidden();
});

it('answers 403 for an unknown photo rather than 404', function (): void {
    // Un 404 dirait quels identifiants existent sans présenter de signature.
    $this->get(route('api.v1.shop.orders.documents.show', ['document' => (string) Str::ulid()]))
        ->assertForbidden();
});

/**
 * @return array<string, mixed>
 */
function shopDocumentPayload(): array
{
    return [
        'lines' => [['product_id' => test()->product->id, 'qty' => 1]],
        'fulfilment_mode' => FulfilmentMode::Pickup->value,
        'pickup_point_id' => test()->pickupPoint->id,
        'documents' => [
            UploadedFile::fake()->image('cg-1.jpg'),
            UploadedFile::fake()->image('cg-2.jpg'),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function shopDocumentPost(array $payload): TestResponse
{
    return test()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('Accept', 'application/json')
        ->post(route('api.v1.shop.orders.store'), $payload);
}

function shopDocumentOrder(): TestResponse
{
    return shopDocumentPost(shopDocumentPayload());
}

function shopDocumentSignedUrl(ShopOrderDocument $document): string
{
    return URL::temporarySignedRoute(
        'api.v1.shop.orders.documents.show',
        now()->addMinutes(60),
        ['document' => $document->id],
    );
}
