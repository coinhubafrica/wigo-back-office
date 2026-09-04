<?php

namespace App\Livewire\Shop;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Catalogue des pièces : recherche, fiche produit, référentiel des modèles.
 *
 * Le catalogue ne suit pas de stock : une pièce porte une référence, un prix
 * et un booléen d'ouverture à la commande. Lire suit la permission du module ;
 * écrire demande en plus `manageCatalogue`.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Shop])]
class Catalogue extends Component
{
    use InteractsWithCurrentUser, WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $vehicleModel = null;

    // Fiche produit.
    public bool $formOpen = false;

    public ?string $editingId = null;

    public string $reference = '';

    public string $name = '';

    public string $description = '';

    public int $unitPrice = 0;

    public ?string $partCategoryId = null;

    public ?string $productVehicleModelId = null;

    public bool $isActive = true;

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

    public function newProduct(): void
    {
        Gate::authorize('manageCatalogue');

        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('manageCatalogue');

        $product = Product::query()->findOrFail($id);

        $this->editingId = $product->id;
        $this->reference = $product->reference;
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->unitPrice = $product->unit_price;
        $this->partCategoryId = $product->part_category_id;
        $this->productVehicleModelId = $product->vehicle_model_id;
        $this->isActive = $product->is_active;
        $this->photo = null;
        $this->formOpen = true;
    }

    public function save(): void
    {
        Gate::authorize('manageCatalogue');

        $validated = $this->validate([
            'reference' => 'required|string|max:64|unique:products,reference'.($this->editingId === null ? '' : ",{$this->editingId}"),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'unitPrice' => 'required|integer|min:0',
            'partCategoryId' => 'nullable|exists:part_categories,id',
            'productVehicleModelId' => 'nullable|exists:vehicle_models,id',
            'isActive' => 'boolean',
            'photo' => 'nullable|image|max:5120',
        ]);

        $attributes = [
            'reference' => $validated['reference'],
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'unit_price' => $validated['unitPrice'],
            'part_category_id' => $validated['partCategoryId'],
            'vehicle_model_id' => $validated['productVehicleModelId'],
            'is_active' => $this->isActive,
        ];

        if ($this->photo !== null) {
            // Disque par défaut : `public` en local, S3 en recette.
            $attributes['photo_url'] = $this->photo->store(path: 'products');
        }

        if ($this->editingId === null) {
            Product::query()->create($attributes);
            $this->dispatch('toast', message: __('backoffice.shop.product_created'));
        } else {
            $product = Product::query()->findOrFail($this->editingId);
            $priceBefore = $product->unit_price;
            $product->update($attributes);

            /*
            | Seul le *mouvement de prix* est journalisé, pas l'enregistrement :
            | un prix est ce qu'un conducteur paie, tandis que corriger un
            | libellé ou une photo laisse la ligne elle-même comme preuve. Même
            | règle que `user.updated`, qui ne garde que les champs sensibles —
            | un journal encombré ne se lit pas.
            */
            if ($priceBefore !== $product->unit_price) {
                AuditLog::record(
                    action: AuditAction::ShopPriceChanged->value,
                    summary: "{$this->actor()->fullName()} a changé le prix de « {$product->name} » de {$priceBefore} à {$product->unit_price} FCFA.",
                    subject: $product,
                    by: $this->actor(),
                    context: ['price_before' => $priceBefore, 'price_after' => $product->unit_price],
                );
            }

            $this->dispatch('toast', message: __('backoffice.shop.product_updated'));
        }

        $this->formOpen = false;
        $this->resetForm();
    }

    public function confirmDelete(string $id): void
    {
        Gate::authorize('manageCatalogue');

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        Gate::authorize('manageCatalogue');

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

        // Enregistré avant la suppression : après, il ne reste rien à citer —
        // c'est pourquoi une suppression dure se journalise là où un simple
        // enregistrement ne le fait pas.
        AuditLog::record(
            action: AuditAction::ShopProductDeleted->value,
            summary: "{$this->actor()->fullName()} a supprimé la référence « {$product->name} » ({$product->reference}).",
            by: $this->actor(),
            context: ['reference' => $product->reference, 'price' => $product->unit_price],
        );

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
        Gate::authorize('manageCatalogue');

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
        Gate::authorize('manageCatalogue');

        $this->validate(['newBrandName' => 'required|string|max:64|unique:vehicle_brands,name']);

        VehicleBrand::query()->create(['name' => mb_strtoupper($this->newBrandName), 'is_active' => true]);

        $this->newBrandName = '';
        $this->dispatch('toast', message: __('backoffice.shop.brand_created'));
    }

    public function addModel(): void
    {
        Gate::authorize('manageCatalogue');

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
        Gate::authorize('manageCatalogue');

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
            'partCategoryId', 'productVehicleModelId', 'photo',
        ]);
        $this->isActive = true;
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
            'canManageCatalogue' => Gate::allows('manageCatalogue'),
            'referenceCount' => Product::query()->count(),
            'activeCount' => Product::query()->where('is_active', true)->count(),
            'inactiveCount' => Product::query()->where('is_active', false)->count(),
            'orderCount' => ShopOrder::query()->count(),
        ]);
    }
}
