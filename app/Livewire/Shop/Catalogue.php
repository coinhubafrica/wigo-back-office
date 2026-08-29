<?php

namespace App\Livewire\Shop;

use App\Enums\BackOfficeModule;
use App\Enums\ProductStatus;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Services\Shop\StockService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Catalogue des pièces : recherche, stock, approvisionnement, fiche produit.
 *
 * Lire le catalogue suit la permission du module ; l'écrire demande en plus
 * `manageStock` — un gestionnaire suit la boutique sans toucher au stock.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Shop])]
class Catalogue extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $vehicleModel = null;

    // Approvisionnement.
    public ?string $restockingId = null;

    public int $restockQuantity = 10;

    public string $restockReason = '';

    // Fiche produit.
    public bool $formOpen = false;

    public ?string $editingId = null;

    public string $reference = '';

    public string $name = '';

    public string $description = '';

    public int $unitPrice = 0;

    public int $stockQuantity = 0;

    public int $lowStockThreshold = 5;

    public ?string $partCategoryId = null;

    public ?string $productVehicleModelId = null;

    public string $status = 'active';

    public mixed $photo = null;

    public ?string $confirmingDeleteId = null;

    // Référentiel marques / modèles.
    public bool $referentialOpen = false;

    public string $newBrandName = '';

    public ?string $newModelBrandId = null;

    public string $newModelName = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filterByCategory(?string $category): void
    {
        $this->category = $category;
        $this->resetPage();
    }

    public function filterByVehicleModel(?string $vehicleModel): void
    {
        $this->vehicleModel = $vehicleModel;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->category = null;
        $this->vehicleModel = null;
        $this->resetPage();
    }

    public function startRestock(string $id): void
    {
        Gate::authorize('manageStock');

        $this->restockingId = $id;
        $this->restockQuantity = 10;
        $this->restockReason = '';
        $this->resetValidation();
    }

    public function cancelRestock(): void
    {
        $this->restockingId = null;
        $this->resetValidation();
    }

    public function restock(StockService $stock): void
    {
        Gate::authorize('manageStock');

        if ($this->restockingId === null) {
            return;
        }

        $this->validate([
            'restockQuantity' => 'required|integer|min:1|max:1000',
            'restockReason' => 'required|string|max:255',
        ]);

        /** @var User $user */
        $user = auth()->user();

        $stock->restock(
            Product::query()->findOrFail($this->restockingId),
            $this->restockQuantity,
            $this->restockReason,
            $user,
        );

        $this->restockingId = null;
        $this->dispatch('toast', message: __('backoffice.shop.restocked'));
    }

    public function newProduct(): void
    {
        Gate::authorize('manageStock');

        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('manageStock');

        $product = Product::query()->findOrFail($id);

        $this->editingId = $product->id;
        $this->reference = $product->reference;
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->unitPrice = $product->unit_price;
        $this->stockQuantity = $product->stock_quantity;
        $this->lowStockThreshold = $product->low_stock_threshold;
        $this->partCategoryId = $product->part_category_id;
        $this->productVehicleModelId = $product->vehicle_model_id;
        $this->status = $product->status->value;
        $this->photo = null;
        $this->formOpen = true;
    }

    public function save(StockService $stock): void
    {
        Gate::authorize('manageStock');

        $validated = $this->validate([
            'reference' => 'required|string|max:64|unique:products,reference'.($this->editingId === null ? '' : ",{$this->editingId}"),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'unitPrice' => 'required|integer|min:0',
            'stockQuantity' => 'required|integer|min:0',
            'lowStockThreshold' => 'required|integer|min:0',
            'partCategoryId' => 'nullable|exists:part_categories,id',
            'productVehicleModelId' => 'nullable|exists:vehicle_models,id',
            'status' => 'required|in:active,out_of_stock,backorder',
            'photo' => 'nullable|image|max:5120',
        ]);

        $attributes = [
            'reference' => $validated['reference'],
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'unit_price' => $validated['unitPrice'],
            'stock_quantity' => $validated['stockQuantity'],
            'low_stock_threshold' => $validated['lowStockThreshold'],
            'part_category_id' => $validated['partCategoryId'],
            'vehicle_model_id' => $validated['productVehicleModelId'],
            'status' => ProductStatus::from($validated['status']),
        ];

        if ($this->photo !== null) {
            // Disque par défaut : `public` en local, S3 en recette.
            $attributes['photo_url'] = $this->photo->store(path: 'products');
        }

        if ($this->editingId === null) {
            $product = Product::query()->create($attributes);
            $this->dispatch('toast', message: __('backoffice.shop.product_created'));
        } else {
            $product = Product::query()->findOrFail($this->editingId);
            $product->update($attributes);
            $this->dispatch('toast', message: __('backoffice.shop.product_updated'));
        }

        $stock->syncStatus($product);

        $this->formOpen = false;
        $this->resetForm();
    }

    public function confirmDelete(string $id): void
    {
        Gate::authorize('manageStock');

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        Gate::authorize('manageStock');

        if ($this->confirmingDeleteId === null) {
            return;
        }

        $product = Product::query()->findOrFail($this->confirmingDeleteId);

        // Une pièce commandée est citée dans des lignes de commande : la
        // supprimer effacerait l'historique d'achat du conducteur.
        if ($product->orderItems()->exists()) {
            $this->confirmingDeleteId = null;
            $this->dispatch('toast', message: __('backoffice.shop.product_delete_blocked'));

            return;
        }

        $product->delete();

        $this->confirmingDeleteId = null;
        $this->dispatch('toast', message: __('backoffice.shop.product_deleted'));
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    public function openReferential(): void
    {
        Gate::authorize('manageStock');

        $this->referentialOpen = true;
        $this->resetValidation();
    }

    public function closeReferential(): void
    {
        $this->referentialOpen = false;
        $this->newBrandName = '';
        $this->newModelName = '';
        $this->newModelBrandId = null;
        $this->resetValidation();
    }

    public function addBrand(): void
    {
        Gate::authorize('manageStock');

        $this->validate(['newBrandName' => 'required|string|max:64|unique:vehicle_brands,name']);

        VehicleBrand::query()->create(['name' => mb_strtoupper($this->newBrandName), 'is_active' => true]);

        $this->newBrandName = '';
        $this->dispatch('toast', message: __('backoffice.shop.brand_created'));
    }

    public function addModel(): void
    {
        Gate::authorize('manageStock');

        $this->validate([
            'newModelBrandId' => 'required|exists:vehicle_brands,id',
            'newModelName' => 'required|string|max:64',
        ]);

        VehicleModel::query()->firstOrCreate(
            ['vehicle_brand_id' => $this->newModelBrandId, 'name' => $this->newModelName],
            ['is_active' => true],
        );

        $this->newModelName = '';
        $this->dispatch('toast', message: __('backoffice.shop.model_created'));
    }

    public function deleteModel(string $id): void
    {
        Gate::authorize('manageStock');

        $model = VehicleModel::query()->findOrFail($id);

        if ($model->products()->exists()) {
            $this->dispatch('toast', message: __('backoffice.shop.model_delete_blocked'));

            return;
        }

        $model->delete();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'reference', 'name', 'description', 'unitPrice',
            'stockQuantity', 'lowStockThreshold', 'partCategoryId',
            'productVehicleModelId', 'photo',
        ]);
        $this->lowStockThreshold = 5;
        $this->status = 'active';
        $this->resetValidation();
    }

    public function render(): View
    {
        $products = Product::query()
            ->with(['partCategory', 'vehicleModel.vehicleBrand'])
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'like', $term)->orWhere('reference', 'like', $term);
                });
            })
            ->when($this->category !== null, fn (Builder $query) => $query->where('part_category_id', $this->category))
            ->when($this->vehicleModel !== null, fn (Builder $query) => $query->where('vehicle_model_id', $this->vehicleModel))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.shop.catalogue', [
            'products' => $products,
            'categories' => PartCategory::query()->orderBy('order')->get(),
            'brands' => VehicleBrand::query()->with('vehicleModels')->orderBy('name')->get(),
            'vehicleModels' => VehicleModel::query()->with('vehicleBrand')->orderBy('name')->get(),
            'canManageStock' => Gate::allows('manageStock'),
            'referenceCount' => Product::query()->count(),
            'stockValue' => (int) Product::query()->selectRaw('coalesce(sum(unit_price * stock_quantity), 0) as total')->value('total'),
            'alertCount' => Product::query()->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count(),
            'orderCount' => ShopOrder::query()->count(),
        ]);
    }
}
