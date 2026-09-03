<?php

namespace App\Livewire\Shop;

use App\Enums\BackOfficeModule;
use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\ShopOrder;
use App\Models\User;
use App\Services\Shop\ShopOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * File des commandes boutique : liste filtrable à gauche, détail et
 * transitions à droite.
 *
 * L'écran n'offre que les transitions autorisées par
 * `ShopOrderStatus::allowedTransitions()` ; le service refuse le reste.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Shop])]
class Orders extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $selected = null;

    public string $pickupCode = '';

    public bool $cancelling = false;

    public string $cancelReason = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filterByStatus(?string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function select(string $id): void
    {
        $this->selected = $id;
        $this->pickupCode = '';
        $this->cancelling = false;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function markReady(ShopOrderService $orders): void
    {
        $this->apply(fn (ShopOrder $order) => $orders->markReady($order));
    }

    public function markDispatched(ShopOrderService $orders): void
    {
        $this->apply(fn (ShopOrder $order) => $orders->dispatchToDelivery($order));
    }

    public function markDelivered(ShopOrderService $orders): void
    {
        $this->apply(fn (ShopOrder $order) => $orders->deliver($order));
    }

    /**
     * Retrait au comptoir : le code présenté doit correspondre.
     */
    public function markCollected(ShopOrderService $orders): void
    {
        $this->validate(['pickupCode' => 'required|digits:6']);

        $this->apply(function (ShopOrder $order) use ($orders): ShopOrder {
            try {
                return $orders->collect($order, $this->pickupCode);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'pickupCode' => $e->validator->errors()->first(),
                ]);
            }
        });

        $this->pickupCode = '';
    }

    public function startCancel(): void
    {
        $this->cancelling = true;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function cancelCancel(): void
    {
        $this->cancelling = false;
        $this->cancelReason = '';
    }

    public function cancelOrder(ShopOrderService $orders): void
    {
        $this->validate(['cancelReason' => 'required|string|max:255']);

        /** @var User $user */
        $user = auth()->user();

        $this->apply(
            fn (ShopOrder $order) => $orders->cancel($order, $this->cancelReason, $user),
            message: __('backoffice.shop.order_cancelled'),
        );

        $this->cancelling = false;
        $this->cancelReason = '';
    }

    /**
     * @param  callable(ShopOrder): ShopOrder  $action
     */
    private function apply(callable $action, ?string $message = null): void
    {
        Gate::authorize('manageCatalogue');

        if ($this->selected === null) {
            return;
        }

        $action(ShopOrder::query()->findOrFail($this->selected));

        $this->dispatch('toast', message: $message ?? __('backoffice.shop.order_updated'));
    }

    public function render(): View
    {
        $orders = ShopOrder::query()
            ->with('driver')
            ->withCount('items')
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('reference', 'like', $term)
                        ->orWhereHas('driver', function (Builder $query) use ($term): void {
                            $query->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term);
                        });
                });
            })
            ->when($this->status !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->orderByDesc('ordered_at')
            ->paginate(20);

        $selected = $this->selected === null
            ? null
            : ShopOrder::query()->with(['driver', 'items', 'delivery.pickupPoint'])->find($this->selected);

        return view('livewire.shop.orders', [
            'orders' => $orders,
            'selectedOrder' => $selected,
            'statuses' => ShopOrderStatus::cases(),
            'transitions' => $selected?->status->allowedTransitions() ?? [],
            'canManageCatalogue' => Gate::allows('manageCatalogue'),
            'pickupMode' => FulfilmentMode::Pickup,
        ]);
    }
}
