@if ($formOpen)
    <x-modal close="closeForm" max-width="max-w-2xl" align="start"
             :title="$editingId === null ? __('backoffice.shop.new_product') : __('backoffice.shop.edit_product')">
            <form wire:submit="save" class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <div>
                    <label for="reference" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_reference') }}</label>
                    <input wire:model="reference" id="reference" type="text" class="block w-full rounded border border-input px-3 py-2 font-mono text-sm focus:border-primary">
                    @error('reference') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_name') }}</label>
                    <input wire:model="name" id="name" type="text" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('name') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_description') }}</label>
                    <textarea wire:model="description" id="description" rows="2" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary"></textarea>
                    @error('description') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="unitPrice" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_price') }}</label>
                    <input wire:model="unitPrice" id="unitPrice" type="number" min="0" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('unitPrice') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="partCategoryId" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_category') }}</label>
                    <select wire:model="partCategoryId" id="partCategoryId" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        <option value="">—</option>
                        @foreach ($categories as $partCategory)
                            <option value="{{ $partCategory->id }}">{{ $partCategory->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label for="productVehicleModelId" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_model') }}</label>
                    <select wire:model="productVehicleModelId" id="productVehicleModelId" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        <option value="">{{ __('backoffice.shop.field_model_universal') }}</option>
                        @foreach ($brands as $brand)
                            <optgroup label="{{ $brand->name }}">
                                @foreach ($brand->vehicleModels as $model)
                                    <option value="{{ $model->id }}">{{ $model->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="stockQuantity" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_stock') }}</label>
                    <input wire:model="stockQuantity" id="stockQuantity" type="number" min="0" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('stockQuantity') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="lowStockThreshold" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_threshold') }}</label>
                    <input wire:model="lowStockThreshold" id="lowStockThreshold" type="number" min="0" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('lowStockThreshold') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="status" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_status') }}</label>
                    <select wire:model="status" id="status" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @foreach (\App\Enums\ProductStatus::cases() as $productStatus)
                            <option value="{{ $productStatus->value }}">{{ $productStatus->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.field_photo') }}</label>
                    <input wire:model="photo" type="file" accept="image/*"
                           class="block w-full rounded border border-input px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-sm">
                    <div wire:loading wire:target="photo" class="mt-1.5 text-xs text-muted">{{ __('backoffice.announcements.uploading') }}</div>
                    @error('photo') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-1 sm:col-span-2">
                    <button type="button" wire:click="closeForm" class="rounded border border-line px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.announcements.cancel') }}
                    </button>
                    <button type="submit" class="rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                        {{ __('backoffice.announcements.save') }}
                    </button>
                </div>
            </form>
    </x-modal>
@endif
