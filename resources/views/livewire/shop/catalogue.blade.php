<div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.kpi_references') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ $referenceCount }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.kpi_stock_value') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ok-text">{{ number_format($stockValue, 0, ',', ' ') }} FCFA</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.kpi_alerts') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-err-text">{{ $alertCount }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.kpi_orders') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">
                <a href="{{ route('bo.shop.orders') }}" class="hover:text-primary">{{ $orderCount }}</a>
            </p>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        {{-- `aria-pressed` : la sélection est signalée par la couleur seule,
             invisible pour un lecteur d'écran sans cet état. --}}
        <button wire:click="filterByCategory(null)"
                aria-pressed="{{ $category === null ? 'true' : 'false' }}"
                @class(['rounded-full border px-3.5 py-1.5 text-xs font-semibold', 'border-primary bg-primary-tint text-primary-text' => $category === null, 'border-line text-muted hover:bg-surface' => $category !== null])>
            {{ __('backoffice.shop.all_categories') }}
        </button>
        @foreach ($categories as $partCategory)
            <button wire:key="cat-{{ $partCategory->id }}" wire:click="filterByCategory('{{ $partCategory->id }}')"
                    aria-pressed="{{ $category === $partCategory->id ? 'true' : 'false' }}"
                    @class(['rounded-full border px-3.5 py-1.5 text-xs font-semibold', 'border-primary bg-primary-tint text-primary-text' => $category === $partCategory->id, 'border-line text-muted hover:bg-surface' => $category !== $partCategory->id])>
                {{ $partCategory->name }}
            </button>
        @endforeach

        <span class="flex-1"></span>

        {{-- L'anneau de focus est porté par l'enveloppe : le champ est sans
             bordure, un anneau sur le champ seul serait collé à l'icône. --}}
        <div class="flex items-center gap-2 rounded border border-line bg-card px-3 py-1.5 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary">
            <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.400ms="search" type="search" placeholder="{{ __('backoffice.shop.search_placeholder') }}"
                   class="w-48 border-0 p-0 text-sm focus:outline-none">
        </div>

        @if ($canManageStock)
            <button wire:click="openReferential" class="rounded border border-line px-3 py-2 text-xs font-semibold text-muted hover:bg-surface">
                {{ __('backoffice.shop.manage_brands') }}
            </button>
            <button wire:click="newProduct" class="flex items-center gap-1.5 rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                {{ __('backoffice.shop.new_product') }}
            </button>
        @endif
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
        <div class="overflow-hidden rounded border border-line bg-card">
            <div class="border-b border-line px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.catalogue') }}</p>
            </div>

            @if ($products->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-muted">{{ __('backoffice.shop.none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-surface">
                                <th class="w-[70px] border-b border-line px-4 py-2.5"></th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_part') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_model') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_price') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_stock') }}</th>
                                <th class="border-b border-line px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                                <tr wire:key="product-{{ $product->id }}" class="border-b border-line last:border-0">
                                    <td class="px-4 py-3">
                                        @if ($product->photo_url !== null)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($product->photo_url) }}" alt="{{ $product->name }}" class="size-[46px] rounded border border-line object-cover">
                                        @else
                                            <div class="size-[46px] rounded border border-line bg-surface"></div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-semibold text-ink">{{ $product->name }}</p>
                                        <p class="font-mono text-xs text-muted">{{ $product->reference }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-muted">
                                        @if ($product->isUniversal())
                                            <span class="rounded-full bg-neutral-bg px-2.5 py-1 text-xs font-semibold text-neutral-text">{{ __('backoffice.shop.universal') }}</span>
                                        @else
                                            {{ $product->vehicleModel->fullName() }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm font-semibold text-ink">{{ number_format($product->unit_price, 0, ',', ' ') }} FCFA</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $product->stockBadgeClasses() }}">{{ $product->stockLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($canManageStock)
                                            <div class="flex items-center justify-end gap-2">
                                                <button wire:click="startRestock('{{ $product->id }}')" class="flex items-center gap-1.5 rounded bg-ok-text px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                                    {{ __('backoffice.shop.restock') }}
                                                </button>
                                                <button wire:click="edit('{{ $product->id }}')" class="rounded border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-line">
                                                    {{ __('backoffice.announcements.modify') }}
                                                </button>
                                                <button wire:click="confirmDelete('{{ $product->id }}')" title="{{ __('backoffice.announcements.delete') }}"
                                                        class="flex items-center justify-center rounded border border-line p-2 text-err-text hover:border-err-text hover:bg-err-bg">
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M9 7V4.8h6V7M6.5 7l.9 12.2A1.5 1.5 0 0 0 8.9 20.6h6.2a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/></svg>
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-line px-4 py-3">{{ $products->links() }}</div>
            @endif
        </div>

        <div class="rounded border border-line bg-card">
            <div class="border-b border-line px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.brands_models') }}</p>
            </div>
            <div class="space-y-3 px-4 py-4">
                @foreach ($brands as $brand)
                    <div wire:key="brand-{{ $brand->id }}" class="flex flex-wrap items-center gap-2">
                        <b class="min-w-[88px] text-sm text-ink">{{ $brand->name }}</b>
                        @foreach ($brand->vehicleModels as $model)
                            <button wire:key="model-chip-{{ $model->id }}" wire:click="filterByVehicleModel('{{ $model->id }}')"
                                    @class(['rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-primary-tint text-primary-text' => $vehicleModel === $model->id, 'bg-surface text-muted hover:bg-line' => $vehicleModel !== $model->id])>
                                {{ $model->name }}
                            </button>
                        @endforeach
                    </div>
                @endforeach

                @if ($vehicleModel !== null)
                    <button wire:click="filterByVehicleModel(null)" class="text-xs font-semibold text-primary-text hover:underline">
                        {{ __('backoffice.shop.all_models') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    @include('livewire.shop.partials.restock-modal')
    @include('livewire.shop.partials.product-form')
    @include('livewire.shop.partials.referential-modal')

    @if ($confirmingDeleteId !== null)
        <x-modal close="cancelDelete" max-width="max-w-sm"
                 :label="__('backoffice.shop.confirm_delete_product')">
            <div class="p-5">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.shop.confirm_delete_product') }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button wire:click="cancelDelete" class="rounded border border-line px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.announcements.cancel') }}
                    </button>
                    <button wire:click="delete" class="rounded bg-err-text px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                        {{ __('backoffice.announcements.delete') }}
                    </button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
