@if ($restockingId !== null)
    @php($restocking = \App\Models\Product::find($restockingId))
    <x-modal close="cancelRestock" :title="__('backoffice.shop.restock_title', ['product' => $restocking?->name])">
        <form id="shop-restock-form" wire:submit="restock" class="space-y-4">
            <x-field :label="__('backoffice.shop.restock_quantity')" name="restockQuantity" type="number" min="1" wire:model="restockQuantity" required />
            <x-field :label="__('backoffice.shop.restock_reason')" name="restockReason" wire:model="restockReason" required />
        </form>

        <x-slot:footer>
            <x-button variant="secondary" wire:click="cancelRestock">{{ __('backoffice.announcements.cancel') }}</x-button>
            <x-button type="submit" form="shop-restock-form" target="restock">
                {{ __('backoffice.shop.restock') }}
                <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
            </x-button>
        </x-slot:footer>
    </x-modal>
@endif
