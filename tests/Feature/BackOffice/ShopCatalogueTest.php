<?php

use App\Enums\BackOfficeModule;
use App\Livewire\Shop\Catalogue;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

it('a permitted user reaches the catalogue', function (): void {
    Product::factory()->create(['name' => 'Radiateur']);

    $this->actingAs(shopCatalogueUser('stock'))
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertOk()
        ->assertSee('Radiateur');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(shopCatalogueUser('admin'))
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertForbidden();
});

it('the search filters on name and reference', function (): void {
    Product::factory()->create(['name' => 'Radiateur', 'reference' => 'RA-DZ-320']);
    Product::factory()->create(['name' => 'Parechoc avant', 'reference' => 'PC-DZ-AV1']);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->set('search', 'RA-DZ')
        ->assertSee('Radiateur')
        ->assertDontSee('Parechoc avant');
});

it('the model filter keeps only that models parts', function (): void {
    $dzire = shopCatalogueVehicleModel('SUZUKI', 'Dzire');
    $corolla = shopCatalogueVehicleModel('TOYOTA', 'Corolla');

    Product::factory()->create(['name' => 'Amortisseur Dzire', 'vehicle_model_id' => $dzire->id]);
    Product::factory()->create(['name' => 'Amortisseur Corolla', 'vehicle_model_id' => $corolla->id]);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('filterByVehicleModel', $dzire->id)
        ->assertSee('Amortisseur Dzire')
        ->assertDontSee('Amortisseur Corolla');
});

it('a product can be closed to ordering from its form', function (): void {
    $product = Product::factory()->create();

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('edit', $product->id)
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertFalse($product->fresh()->is_active);
});

it('a closed product is reopened from its form', function (): void {
    $product = Product::factory()->inactive()->create();

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('edit', $product->id)
        ->set('isActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertTrue($product->fresh()->is_active);
});

it('a manager reads the catalogue but cannot edit it', function (): void {
    $product = Product::factory()->create();

    Livewire::actingAs(shopCatalogueUser('gestionnaire'))
        ->test(Catalogue::class)
        ->call('edit', $product->id)
        ->assertForbidden();
});

it('creating a product stores its photo', function (): void {
    $category = PartCategory::factory()->create();
    $model = shopCatalogueVehicleModel('SUZUKI', 'Dzire');

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('newProduct')
        ->set('reference', 'PL-DZ-118')
        ->set('name', 'Plaquettes frein avant – jeu')
        ->set('unitPrice', 20000)
        ->set('partCategoryId', $category->id)
        ->set('productVehicleModelId', $model->id)
        ->set('photo', UploadedFile::fake()->image('piece.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->where('reference', 'PL-DZ-118')->sole();
    $this->assertSame(20000, $product->unit_price);
    $this->assertSame($model->id, $product->vehicle_model_id);
    Storage::disk('public')->assertExists($product->photo_url);
});

it('a product can be universal', function (): void {
    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('newProduct')
        ->set('reference', 'AM-UNI-001')
        ->set('name', 'Ampoules feux – paire')
        ->set('unitPrice', 6000)
        ->set('productVehicleModelId', null)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertTrue(Product::query()->where('reference', 'AM-UNI-001')->sole()->isUniversal());
});

it('a duplicate reference is refused', function (): void {
    Product::factory()->create(['reference' => 'RA-DZ-320']);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('newProduct')
        ->set('reference', 'RA-DZ-320')
        ->set('name', 'Radiateur bis')
        ->set('unitPrice', 40000)
        ->call('save')
        ->assertHasErrors(['reference']);
});

it('editing a product without a new photo keeps the existing one', function (): void {
    $product = Product::factory()->create(['photo_url' => 'products/original.jpg']);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('edit', $product->id)
        ->set('name', 'Nom modifié')
        ->call('save')
        ->assertHasNoErrors();

    $product->refresh();
    $this->assertSame('Nom modifié', $product->name);
    $this->assertSame('products/original.jpg', $product->photo_url);
});

it('deleting a product that was ordered is blocked', function (): void {
    $product = Product::factory()->create();
    ShopOrderItem::factory()->for(ShopOrder::factory())->create(['product_id' => $product->id]);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('confirmDelete', $product->id)
        ->call('delete');

    $this->assertModelExists($product);
});

it('an unordered product is deleted', function (): void {
    $product = Product::factory()->create();

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('confirmDelete', $product->id)
        ->call('delete');

    $this->assertModelMissing($product);
});

it('a model still carrying parts cannot be deleted', function (): void {
    $model = shopCatalogueVehicleModel('SUZUKI', 'Dzire');
    Product::factory()->create(['vehicle_model_id' => $model->id]);

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('deleteModel', $model->id);

    $this->assertModelExists($model);
});

it('a brand and a model can be added', function (): void {
    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('openReferential')
        ->set('newBrandName', 'suzuki')
        ->call('addBrand')
        ->assertHasNoErrors();

    $brand = VehicleBrand::query()->where('name', 'SUZUKI')->sole();

    Livewire::actingAs(shopCatalogueUser('stock'))
        ->test(Catalogue::class)
        ->call('openReferential')
        ->set('newModelBrandId', $brand->id)
        ->set('newModelName', 'Dzire')
        ->call('addModel')
        ->assertHasNoErrors();

    $this->assertSame(1, VehicleModel::query()->where('vehicle_brand_id', $brand->id)->count());
});

function shopCatalogueVehicleModel(string $brand, string $model): VehicleModel
{
    return VehicleModel::factory()
        ->for(VehicleBrand::factory()->create(['name' => $brand]))
        ->create(['name' => $model]);
}

function shopCatalogueUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
