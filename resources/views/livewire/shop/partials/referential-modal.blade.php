@if ($referentialOpen)
    <x-modal close="closeReferential" align="start" :title="__('backoffice.shop.manage_brands')">
            <div class="space-y-5 px-5 py-4">
                <form wire:submit="addBrand" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label for="newBrandName" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.brand_name') }}</label>
                        <input wire:model="newBrandName" id="newBrandName" type="text" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @error('newBrandName') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    {{-- Icône SVG et nom accessible : le bouton n'affichait qu'un
                         « ＋ » pleine largeur, sans intitulé pour un lecteur d'écran. --}}
                    <button type="submit" aria-label="{{ __('backoffice.shop.add_brand') }}"
                            class="flex items-center justify-center rounded bg-primary px-3.5 py-2.5 text-white hover:bg-primary-hover">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </form>

                <form wire:submit="addModel" class="flex items-end gap-2">
                    <div class="w-40">
                        <label for="newModelBrandId" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.brand_name') }}</label>
                        <select wire:model="newModelBrandId" id="newModelBrandId" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                            <option value="">—</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                        @error('newModelBrandId') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex-1">
                        <label for="newModelName" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.shop.model_name') }}</label>
                        <input wire:model="newModelName" id="newModelName" type="text" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @error('newModelName') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" aria-label="{{ __('backoffice.shop.add_model') }}"
                            class="flex items-center justify-center rounded bg-primary px-3.5 py-2.5 text-white hover:bg-primary-hover">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </form>

                <div class="space-y-3 border-t border-line pt-4">
                    @foreach ($brands as $brand)
                        <div wire:key="ref-brand-{{ $brand->id }}" class="flex flex-wrap items-center gap-2">
                            <b class="min-w-[88px] text-sm text-ink">{{ $brand->name }}</b>
                            @foreach ($brand->vehicleModels as $model)
                                <span wire:key="ref-model-{{ $model->id }}" class="flex items-center gap-1 rounded-full bg-surface py-1 pl-2.5 pr-1 text-xs font-semibold text-muted">
                                    {{ $model->name }}
                                    {{-- Croix en SVG, avec un intitulé et une cible de
                                         24 px : le « × » nu n'avait ni nom accessible
                                         ni surface cliquable suffisante. --}}
                                    <button wire:click="deleteModel('{{ $model->id }}')"
                                            aria-label="{{ __('backoffice.shop.delete_model', ['model' => $model->name]) }}"
                                            class="flex size-6 items-center justify-center rounded-full text-err-text hover:bg-err-bg">
                                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
    </x-modal>
@endif
