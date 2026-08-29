<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Livewire\Shop\Catalogue;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ShopCatalogueTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    public function test_a_permitted_user_reaches_the_catalogue(): void
    {
        Product::factory()->create(['name' => 'Radiateur']);

        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Shop->route()))
            ->assertOk()
            ->assertSee('Radiateur');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('admin'))
            ->get(route(BackOfficeModule::Shop->route()))
            ->assertForbidden();
    }

    public function test_the_search_filters_on_name_and_reference(): void
    {
        Product::factory()->create(['name' => 'Radiateur', 'reference' => 'RA-DZ-320']);
        Product::factory()->create(['name' => 'Parechoc avant', 'reference' => 'PC-DZ-AV1']);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->set('search', 'RA-DZ')
            ->assertSee('Radiateur')
            ->assertDontSee('Parechoc avant');
    }

    public function test_the_model_filter_keeps_only_that_models_parts(): void
    {
        $dzire = $this->vehicleModel('SUZUKI', 'Dzire');
        $corolla = $this->vehicleModel('TOYOTA', 'Corolla');

        Product::factory()->create(['name' => 'Amortisseur Dzire', 'vehicle_model_id' => $dzire->id]);
        Product::factory()->create(['name' => 'Amortisseur Corolla', 'vehicle_model_id' => $corolla->id]);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('filterByVehicleModel', $dzire->id)
            ->assertSee('Amortisseur Dzire')
            ->assertDontSee('Amortisseur Corolla');
    }

    public function test_restocking_writes_a_movement_and_lifts_the_out_of_stock_status(): void
    {
        $product = Product::factory()->outOfStock()->create();
        $user = $this->user('stock');

        Livewire::actingAs($user)
            ->test(Catalogue::class)
            ->call('startRestock', $product->id)
            ->set('restockQuantity', 20)
            ->set('restockReason', 'Réassort fournisseur')
            ->call('restock')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame(20, $product->stock_quantity);
        $this->assertSame(ProductStatus::Active, $product->status);

        $movement = StockMovement::query()->where('product_id', $product->id)->sole();
        $this->assertSame(StockMovementType::In, $movement->movement_type);
        $this->assertSame(20, $movement->quantity);
        $this->assertSame($user->id, $movement->user_id);
    }

    public function test_restocking_requires_a_quantity_and_a_reason(): void
    {
        $product = Product::factory()->create();

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('startRestock', $product->id)
            ->set('restockQuantity', 0)
            ->set('restockReason', '')
            ->call('restock')
            ->assertHasErrors(['restockQuantity', 'restockReason']);
    }

    public function test_a_manager_reads_the_catalogue_but_cannot_restock(): void
    {
        $product = Product::factory()->create();

        Livewire::actingAs($this->user('gestionnaire'))
            ->test(Catalogue::class)
            ->call('startRestock', $product->id)
            ->assertForbidden();
    }

    public function test_creating_a_product_stores_its_photo(): void
    {
        $category = PartCategory::factory()->create();
        $model = $this->vehicleModel('SUZUKI', 'Dzire');

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('newProduct')
            ->set('reference', 'PL-DZ-118')
            ->set('name', 'Plaquettes frein avant – jeu')
            ->set('unitPrice', 20000)
            ->set('stockQuantity', 7)
            ->set('partCategoryId', $category->id)
            ->set('productVehicleModelId', $model->id)
            ->set('photo', UploadedFile::fake()->image('piece.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->where('reference', 'PL-DZ-118')->sole();
        $this->assertSame(20000, $product->unit_price);
        $this->assertSame($model->id, $product->vehicle_model_id);
        Storage::disk('public')->assertExists($product->photo_url);
    }

    public function test_a_product_can_be_universal(): void
    {
        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('newProduct')
            ->set('reference', 'AM-UNI-001')
            ->set('name', 'Ampoules feux – paire')
            ->set('unitPrice', 6000)
            ->set('stockQuantity', 40)
            ->set('productVehicleModelId', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Product::query()->where('reference', 'AM-UNI-001')->sole()->isUniversal());
    }

    public function test_a_duplicate_reference_is_refused(): void
    {
        Product::factory()->create(['reference' => 'RA-DZ-320']);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('newProduct')
            ->set('reference', 'RA-DZ-320')
            ->set('name', 'Radiateur bis')
            ->set('unitPrice', 40000)
            ->call('save')
            ->assertHasErrors(['reference']);
    }

    public function test_editing_a_product_without_a_new_photo_keeps_the_existing_one(): void
    {
        $product = Product::factory()->create(['photo_url' => 'products/original.jpg']);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('edit', $product->id)
            ->set('name', 'Nom modifié')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame('Nom modifié', $product->name);
        $this->assertSame('products/original.jpg', $product->photo_url);
    }

    public function test_deleting_a_product_that_was_ordered_is_blocked(): void
    {
        $product = Product::factory()->create();
        ShopOrderItem::factory()->for(ShopOrder::factory())->create(['product_id' => $product->id]);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertModelExists($product);
    }

    public function test_an_unordered_product_is_deleted(): void
    {
        $product = Product::factory()->create();

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertModelMissing($product);
    }

    public function test_a_model_still_carrying_parts_cannot_be_deleted(): void
    {
        $model = $this->vehicleModel('SUZUKI', 'Dzire');
        Product::factory()->create(['vehicle_model_id' => $model->id]);

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('deleteModel', $model->id);

        $this->assertModelExists($model);
    }

    public function test_a_brand_and_a_model_can_be_added(): void
    {
        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('openReferential')
            ->set('newBrandName', 'suzuki')
            ->call('addBrand')
            ->assertHasNoErrors();

        $brand = VehicleBrand::query()->where('name', 'SUZUKI')->sole();

        Livewire::actingAs($this->user('stock'))
            ->test(Catalogue::class)
            ->call('openReferential')
            ->set('newModelBrandId', $brand->id)
            ->set('newModelName', 'Dzire')
            ->call('addModel')
            ->assertHasNoErrors();

        $this->assertSame(1, VehicleModel::query()->where('vehicle_brand_id', $brand->id)->count());
    }

    private function vehicleModel(string $brand, string $model): VehicleModel
    {
        return VehicleModel::factory()
            ->for(VehicleBrand::factory()->create(['name' => $brand]))
            ->create(['name' => $model]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
