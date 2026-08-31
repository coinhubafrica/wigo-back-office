@if ($restockingId !== null)
    @php($restocking = \App\Models\Product::find($restockingId))
    <x-modal close="cancelRestock" max-width="max-w-md"
             :title="__('backoffice.shop.restock_title', ['product' => $restocking?->name])">
            <form wire:submit="restock" class="space-y-4 px-5 py-4">
                <div>
                    <label for="restockQuantity" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.restock_quantity') }}</label>
                    <input wire:model="restockQuantity" id="restockQuantity" type="number" min="1"
                           class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('restockQuantity') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="restockReason" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.restock_reason') }}</label>
                    <input wire:model="restockReason" id="restockReason" type="text"
                           class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('restockReason') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelRestock" class="rounded border border-line px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.announcements.cancel') }}
                    </button>
                    <button type="submit" class="rounded bg-ok-text px-3.5 py-2 text-sm font-semibold text-white hover:opacity-90">
                        {{ __('backoffice.shop.restock') }}
                    </button>
                </div>
            </form>
    </x-modal>
@endif
