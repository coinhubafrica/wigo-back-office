@if ($referentialOpen)
    <div wire:click="closeReferential" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/45 p-6 py-10">
        <div wire:click.stop class="w-full max-w-lg overflow-hidden rounded bg-card shadow-xl">
            <div class="flex items-center gap-3 border-b border-line px-5 py-4">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.shop.manage_brands') }}</p>
                <span class="flex-1"></span>
                <button wire:click="closeReferential" class="flex size-8 items-center justify-center rounded text-muted hover:bg-surface hover:text-ink">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="space-y-5 px-5 py-4">
                <form wire:submit="addBrand" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label for="newBrandName" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.brand_name') }}</label>
                        <input wire:model="newBrandName" id="newBrandName" type="text" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                        @error('newBrandName') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">＋</button>
                </form>

                <form wire:submit="addModel" class="flex items-end gap-2">
                    <div class="w-40">
                        <label for="newModelBrandId" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.brand_name') }}</label>
                        <select wire:model="newModelBrandId" id="newModelBrandId" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                            <option value="">—</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                        @error('newModelBrandId') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex-1">
                        <label for="newModelName" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.model_name') }}</label>
                        <input wire:model="newModelName" id="newModelName" type="text" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                        @error('newModelName') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">＋</button>
                </form>

                <div class="space-y-3 border-t border-line pt-4">
                    @foreach ($brands as $brand)
                        <div wire:key="ref-brand-{{ $brand->id }}" class="flex flex-wrap items-center gap-2">
                            <b class="min-w-[88px] text-sm text-ink">{{ $brand->name }}</b>
                            @foreach ($brand->vehicleModels as $model)
                                <span wire:key="ref-model-{{ $model->id }}" class="flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1 text-xs font-semibold text-muted">
                                    {{ $model->name }}
                                    <button wire:click="deleteModel('{{ $model->id }}')" class="text-err-text hover:opacity-70">×</button>
                                </span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
