{{-- Les actions de module vivent dans l'en-tête du layout et parlent à la
     racine par évènement Alpine (cf. .ai/rules/components.md). --}}
<div x-on:open-shop-product.window="$wire.newProduct()"
     x-on:open-shop-referential.window="$wire.openReferential()">
    <x-slot:actions>
        <a href="{{ route('bo.shop.orders') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded border border-line bg-card px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-surface">
            {{ __('backoffice.shop.kpi_orders') }}
        </a>
        @if ($canManageCatalogue)
            <x-button variant="secondary" x-on:click="$dispatch('open-shop-referential')">{{ __('backoffice.shop.manage_brands') }}</x-button>
            <x-button x-on:click="$dispatch('open-shop-product')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                {{ __('backoffice.shop.new_product') }}
            </x-button>
        @endif
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.shop.kpi_references')" :value="$referenceCount" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.shop.kpi_active')" :value="$activeCount" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.shop.kpi_inactive')" :value="$inactiveCount" :alert="$inactiveCount > 0" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.shop.kpi_orders')" :value="$orderCount" :href="route('bo.shop.orders')" tone="warn">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByCategory(null)" :active="$category === null">{{ __('backoffice.shop.all_categories') }}</x-chip-filter>
            @foreach ($categories as $partCategory)
                <x-chip-filter wire:key="cat-{{ $partCategory->id }}" wire:click="filterByCategory('{{ $partCategory->id }}')" :active="$category === $partCategory->id">
                    {{ $partCategory->name }}
                </x-chip-filter>
            @endforeach
        </div>
        <x-slot:end>
            <x-field :label="__('backoffice.shop.search_placeholder')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.shop.search_placeholder')" class="w-64" />
        </x-slot:end>
    </x-toolbar>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
        <x-panel :title="__('backoffice.shop.catalogue')" :count="$products->total()" flush>
            <x-table loading="filterByCategory,filterByVehicleModel,resetFilters,search,gotoPage,previousPage,nextPage">
                <x-slot:head>
                    <x-th class="w-[70px]"><span class="sr-only">{{ __('backoffice.shop.field_photo') }}</span></x-th>
                    <x-th>{{ __('backoffice.shop.col_part') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_model') }}</x-th>
                    <x-th align="right">{{ __('backoffice.shop.col_price') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_availability') }}</x-th>
                    @if ($canManageCatalogue)
                        <x-th><span class="sr-only">{{ __('backoffice.announcements.modify') }}</span></x-th>
                    @endif
                </x-slot:head>

                @foreach ($products as $product)
                    <tr wire:key="product-{{ $product->id }}" class="transition-colors hover:bg-surface">
                        <x-td>
                            @if ($product->photo_url !== null)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($product->photo_url) }}" alt="{{ $product->name }}" class="size-[46px] rounded border border-line object-cover">
                            @else
                                <div class="size-[46px] rounded border border-line bg-surface" aria-hidden="true"></div>
                            @endif
                        </x-td>
                        <x-td>
                            <p class="text-sm font-semibold text-ink">{{ $product->name }}</p>
                            <p class="font-mono text-xs text-muted">{{ $product->reference }}</p>
                        </x-td>
                        <x-td muted>
                            @if ($product->isUniversal())
                                <x-badge>{{ __('backoffice.shop.universal') }}</x-badge>
                            @else
                                {{ $product->vehicleModel->fullName() }}
                            @endif
                        </x-td>
                        <x-td align="right" nowrap class="font-semibold tabular-nums">{{ number_format($product->unit_price, 0, ',', ' ') }} FCFA</x-td>
                        <x-td><x-badge :classes="$product->availabilityBadgeClasses()">{{ $product->availabilityLabel() }}</x-badge></x-td>
                        @if ($canManageCatalogue)
                            <x-td align="right" nowrap>
                                <div class="flex items-center justify-end gap-2">
                                    <x-button variant="secondary" size="sm" wire:click="edit('{{ $product->id }}')" target="edit">{{ __('backoffice.announcements.modify') }}</x-button>
                                    <x-button variant="danger-outline" size="sm" icon wire:click="confirmDelete('{{ $product->id }}')" target="confirmDelete"
                                              :aria-label="__('backoffice.shop.aria_delete_product', ['product' => $product->name])"
                                              :title="__('backoffice.announcements.delete')">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4.8h6V7M6.5 7l.9 12.2A1.5 1.5 0 0 0 8.9 20.6h6.2a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/></svg>
                                    </x-button>
                                </div>
                            </x-td>
                        @endif
                    </tr>
                @endforeach

                @if ($products->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :hint="__('backoffice.shop.none')">
                            <x-slot:action>
                                <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">{{ __('backoffice.common.reset_filters') }}</x-button>
                            </x-slot:action>
                        </x-empty-state>
                    </x-slot:empty>
                @endif

                @if ($products->hasPages())
                    <x-slot:footer>{{ $products->links() }}</x-slot:footer>
                @endif
            </x-table>
        </x-panel>

        <x-panel :title="__('backoffice.shop.brands_models')">
            <div class="space-y-3">
                @foreach ($brands as $brand)
                    <div wire:key="brand-{{ $brand->id }}" class="flex flex-wrap items-center gap-1.5">
                        <b class="min-w-[88px] text-sm text-ink">{{ $brand->name }}</b>
                        @foreach ($brand->vehicleModels as $model)
                            <x-chip-filter wire:key="model-chip-{{ $model->id }}" wire:click="filterByVehicleModel('{{ $model->id }}')" :active="$vehicleModel === $model->id">
                                {{ $model->name }}
                            </x-chip-filter>
                        @endforeach
                    </div>
                @endforeach

                @if ($vehicleModel !== null)
                    <x-button variant="secondary" size="sm" wire:click="filterByVehicleModel(null)">{{ __('backoffice.shop.all_models') }}</x-button>
                @endif
            </div>
        </x-panel>
    </div>

    @include('livewire.shop.partials.product-form')
    @include('livewire.shop.partials.referential-modal')

    @if ($confirmingDeleteId !== null)
        <x-confirm close="cancelDelete" action="delete" variant="danger"
                   :title="__('backoffice.shop.confirm_delete_product')"
                   :confirm-label="__('backoffice.announcements.delete')"
                   :loading="__('backoffice.common.deleting')" />
    @endif
</div>
