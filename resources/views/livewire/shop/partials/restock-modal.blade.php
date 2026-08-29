@if ($restockingId !== null)
    @php($restocking = \App\Models\Product::find($restockingId))
    <div wire:click="cancelRestock" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-6">
        <div wire:click.stop class="w-full max-w-md overflow-hidden rounded bg-card shadow-xl">
            <div class="flex items-center gap-3 border-b border-line px-5 py-4">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.shop.restock_title', ['product' => $restocking?->name]) }}</p>
                <span class="flex-1"></span>
                <button wire:click="cancelRestock" class="flex size-8 items-center justify-center rounded text-muted hover:bg-surface hover:text-ink">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form wire:submit="restock" class="space-y-4 px-5 py-4">
                <div>
                    <label for="restockQuantity" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.restock_quantity') }}</label>
                    <input wire:model="restockQuantity" id="restockQuantity" type="number" min="1"
                           class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                    @error('restockQuantity') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="restockReason" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.restock_reason') }}</label>
                    <input wire:model="restockReason" id="restockReason" type="text"
                           class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
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
        </div>
    </div>
@endif
