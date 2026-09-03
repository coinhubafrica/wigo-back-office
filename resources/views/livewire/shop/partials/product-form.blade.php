@if ($formOpen)
    <x-modal close="closeForm" size="lg" align="start"
             :title="$editingId === null ? __('backoffice.shop.new_product') : __('backoffice.shop.edit_product')">
        <form id="shop-product-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('backoffice.shop.field_reference')" name="reference" wire:model="reference" class="font-mono" required />
            <x-field :label="__('backoffice.shop.field_name')" name="name" wire:model="name" required />
            <x-field :label="__('backoffice.shop.field_description')" name="description" type="textarea" rows="2" wire:model="description" class="sm:col-span-2" />
            <x-field :label="__('backoffice.shop.field_price')" name="unitPrice" type="number" min="0" wire:model="unitPrice" required />

            <x-field :label="__('backoffice.shop.field_category')" name="partCategoryId" type="select" wire:model="partCategoryId">
                <option value="">—</option>
                @foreach ($categories as $partCategory)
                    <option value="{{ $partCategory->id }}">{{ $partCategory->name }}</option>
                @endforeach
            </x-field>

            <x-field :label="__('backoffice.shop.field_model')" name="productVehicleModelId" type="select" wire:model="productVehicleModelId" class="sm:col-span-2">
                <option value="">{{ __('backoffice.shop.field_model_universal') }}</option>
                @foreach ($brands as $brand)
                    <optgroup label="{{ $brand->name }}">
                        @foreach ($brand->vehicleModels as $model)
                            <option value="{{ $model->id }}">{{ $model->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-field>

            <div class="sm:col-span-2">
                <label for="product-active" class="flex items-center gap-2.5 text-sm text-ink">
                    <input wire:model="isActive" id="product-active" type="checkbox" class="size-4 rounded border-input text-primary">
                    {{ __('backoffice.shop.field_active') }}
                </label>
                <p class="mt-1 text-xs text-muted">{{ __('backoffice.shop.field_active_hint') }}</p>
            </div>

            <div>
                <x-field :label="__('backoffice.shop.field_photo')" name="photo" type="file" wire:model="photo" accept="image/*" />
                <p wire:loading wire:target="photo" class="mt-1.5 text-xs text-muted">{{ __('backoffice.announcements.uploading') }}</p>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.announcements.cancel') }}</x-button>
            <x-button type="submit" form="shop-product-form" target="save">
                {{ __('backoffice.announcements.save') }}
                <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
            </x-button>
        </x-slot:footer>
    </x-modal>
@endif
