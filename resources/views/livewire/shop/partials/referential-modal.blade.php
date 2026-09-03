@if ($referentialOpen)
    <x-modal close="closeReferential" align="start" :title="__('backoffice.shop.manage_brands')">
        <div class="space-y-5">
            <form wire:submit="addBrand" class="flex items-end gap-2">
                <x-field :label="__('backoffice.shop.brand_name')" name="newBrandName" wire:model="newBrandName" class="flex-1" />
                {{-- Icône SVG et nom accessible : le bouton n'affichait qu'un
                     « ＋ » pleine largeur, sans intitulé pour un lecteur d'écran. --}}
                <x-button type="submit" icon :aria-label="__('backoffice.shop.add_brand')" target="addBrand">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                </x-button>
            </form>

            <form wire:submit="addModel" class="flex items-end gap-2">
                <x-field :label="__('backoffice.shop.brand_name')" name="newModelBrandId" type="select" wire:model="newModelBrandId" class="w-40">
                    <option value="">—</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </x-field>
                <x-field :label="__('backoffice.shop.model_name')" name="newModelName" wire:model="newModelName" class="flex-1" />
                <x-button type="submit" icon :aria-label="__('backoffice.shop.add_model')" target="addModel">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                </x-button>
            </form>

            <div class="space-y-3 border-t border-line pt-4">
                @foreach ($brands as $brand)
                    <div wire:key="ref-brand-{{ $brand->id }}" class="flex flex-wrap items-center gap-2">
                        <b class="min-w-[88px] text-sm text-ink">{{ $brand->name }}</b>
                        @foreach ($brand->vehicleModels as $model)
                            <span wire:key="ref-model-{{ $model->id }}" class="flex items-center gap-1 rounded-full bg-neutral-bg py-1 pl-2.5 pr-1 text-xs font-semibold text-neutral-text">
                                {{ $model->name }}
                                {{-- Croix en SVG, avec un intitulé et une cible de
                                     24 px : le « × » nu n'avait ni nom accessible
                                     ni surface cliquable suffisante. --}}
                                <button type="button" wire:click="deleteModel('{{ $model->id }}')"
                                        wire:loading.attr="disabled" wire:target="deleteModel"
                                        aria-label="{{ __('backoffice.shop.delete_model', ['model' => $model->name]) }}"
                                        class="flex size-6 items-center justify-center rounded-full text-err-text transition-colors hover:bg-err-bg disabled:opacity-60">
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
