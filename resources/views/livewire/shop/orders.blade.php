<div>
    <x-slot:actions>
        <a href="{{ route(\App\Enums\BackOfficeModule::Shop->route()) }}" wire:navigate
           class="inline-flex items-center gap-2 rounded border border-line bg-card px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-surface">
            {{ __('backoffice.shop.catalogue') }}
        </a>
    </x-slot:actions>

    <x-toolbar>
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByStatus(null)" :active="$status === null">{{ __('backoffice.shop.all_statuses') }}</x-chip-filter>
            @foreach ($statuses as $orderStatus)
                <x-chip-filter wire:key="status-{{ $orderStatus->value }}" wire:click="filterByStatus('{{ $orderStatus->value }}')" :active="$status === $orderStatus->value">
                    {{ $orderStatus->label() }}
                </x-chip-filter>
            @endforeach
        </div>
        <x-slot:end>
            <x-field :label="__('backoffice.common.search')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.shop.col_reference').' / '.__('backoffice.shop.col_driver')" class="w-64" />
        </x-slot:end>
    </x-toolbar>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <x-panel :title="__('backoffice.shop.orders_title')" :count="$orders->total()" flush>
            <x-table loading="filterByStatus,search,gotoPage,previousPage,nextPage">
                <x-slot:head>
                    <x-th>{{ __('backoffice.shop.col_reference') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_driver') }}</x-th>
                    <x-th align="right">{{ __('backoffice.shop.col_items') }}</x-th>
                    <x-th align="right">{{ __('backoffice.shop.col_total') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_mode') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_status') }}</x-th>
                    <x-th>{{ __('backoffice.shop.col_date') }}</x-th>
                </x-slot:head>

                @foreach ($orders as $order)
                    {{-- Sélection (et non navigation) : la ligne est rendue
                         opérable au clavier plutôt que d'être un simple
                         `wire:click` inatteignable sans souris. --}}
                    <tr wire:key="order-{{ $order->id }}" wire:click="select('{{ $order->id }}')"
                        role="button" tabindex="0"
                        aria-pressed="{{ $selected === $order->id ? 'true' : 'false' }}"
                        aria-label="{{ __('backoffice.shop.select_order') }} {{ $order->reference }}"
                        wire:keydown.enter="select('{{ $order->id }}')"
                        wire:keydown.space.prevent="select('{{ $order->id }}')"
                        @class(['cursor-pointer transition-colors', 'bg-primary-tint' => $selected === $order->id, 'hover:bg-surface' => $selected !== $order->id])>
                        <x-td mono nowrap class="font-semibold">{{ $order->reference }}</x-td>
                        <x-td>{{ $order->driver->first_name }} {{ $order->driver->last_name }}</x-td>
                        <x-td align="right" muted>{{ $order->items_count }}</x-td>
                        <x-td align="right" nowrap class="font-semibold tabular-nums">{{ number_format($order->total_amount, 0, ',', ' ') }} FCFA</x-td>
                        <x-td muted>{{ $order->fulfilment_mode->label() }}</x-td>
                        <x-td><x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge></x-td>
                        <x-td muted nowrap>{{ $order->ordered_at->diffForHumans(short: true) }}</x-td>
                    </tr>
                @endforeach

                @if ($orders->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :hint="__('backoffice.shop.orders_none')" />
                    </x-slot:empty>
                @endif

                @if ($orders->hasPages())
                    <x-slot:footer>{{ $orders->links() }}</x-slot:footer>
                @endif
            </x-table>
        </x-panel>

        @if ($selectedOrder === null)
            <x-panel>
                <x-empty-state tone="primary" :hint="__('backoffice.shop.select_order')" />
            </x-panel>
        @else
            <x-panel :title="$selectedOrder->reference">
                <x-slot:actions>
                    <x-badge :classes="$selectedOrder->status->badgeClasses()">{{ $selectedOrder->status->label() }}</x-badge>
                </x-slot:actions>

                <div class="space-y-4">
                    <x-dl cols="1">
                        <x-dl-item :term="__('backoffice.shop.col_driver')">
                            {{ $selectedOrder->driver->first_name }} {{ $selectedOrder->driver->last_name }} · <span class="font-mono">{{ $selectedOrder->driver->phone }}</span>
                        </x-dl-item>
                    </x-dl>

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_items') }}</p>
                        <ul class="mt-1 space-y-1">
                            @foreach ($selectedOrder->items as $item)
                                <li wire:key="item-{{ $item->id }}" class="flex items-baseline gap-2 text-sm text-ink">
                                    <span class="flex-1">{{ $item->product_name }} ×{{ $item->quantity }}</span>
                                    <span class="font-semibold tabular-nums">{{ number_format($item->line_total, 0, ',', ' ') }} FCFA</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 flex items-baseline gap-2 border-t border-line pt-2 text-sm">
                            <span class="flex-1 font-semibold text-ink">{{ __('backoffice.shop.col_total') }}</span>
                            <span class="font-semibold text-ink tabular-nums">{{ number_format($selectedOrder->total_amount, 0, ',', ' ') }} FCFA</span>
                        </p>
                    </div>

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_mode') }}</p>
                        <p class="mt-0.5 text-sm text-ink">{{ $selectedOrder->fulfilment_mode->label() }}</p>
                        @if ($selectedOrder->fulfilment_mode === $pickupMode)
                            @if ($selectedOrder->delivery?->pickupPoint !== null)
                                <p class="text-sm text-muted">{{ $selectedOrder->delivery->pickupPoint->name }} — {{ $selectedOrder->delivery->pickupPoint->address }}</p>
                            @endif
                            @if ($selectedOrder->pickup_code !== null)
                                <p class="mt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.pickup_code') }}</p>
                                <p class="font-mono text-lg font-semibold tracking-widest text-ink">{{ $selectedOrder->pickup_code }}</p>
                            @endif
                        @else
                            @if ($selectedOrder->delivery !== null)
                                <p class="font-mono text-sm text-muted">{{ $selectedOrder->delivery->latitude }}, {{ $selectedOrder->delivery->longitude }}</p>
                                <p class="text-sm text-muted">{{ __('backoffice.shop.contact') }} : <span class="font-mono">{{ $selectedOrder->delivery->contact_phone }}</span></p>
                            @endif
                        @endif
                    </div>

                    @if ($selectedOrder->cancellation_reason !== null)
                        <x-banner tone="err">{{ $selectedOrder->cancellation_reason }}</x-banner>
                    @endif

                    @if ($canManageCatalogue && $transitions !== [])
                        <div class="space-y-2 border-t border-line pt-4">
                            @foreach ($transitions as $transition)
                                @if ($transition === \App\Enums\ShopOrderStatus::Ready)
                                    <x-button class="w-full" wire:click="markReady" target="markReady">{{ __('backoffice.shop.mark_ready') }}<x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading></x-button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::OutForDelivery)
                                    <x-button class="w-full" wire:click="markDispatched" target="markDispatched">{{ __('backoffice.shop.mark_dispatched') }}<x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading></x-button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::Delivered)
                                    <x-button class="w-full" wire:click="markDelivered" target="markDelivered">{{ __('backoffice.shop.mark_delivered') }}<x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading></x-button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::Collected)
                                    <form wire:submit="markCollected" class="space-y-2">
                                        <x-field :label="__('backoffice.shop.pickup_code_prompt')" name="pickupCode" wire:model="pickupCode"
                                                 inputmode="numeric" maxlength="6" autocomplete="one-time-code" />
                                        <x-button type="submit" class="w-full" target="markCollected">{{ __('backoffice.shop.mark_collected') }}<x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading></x-button>
                                    </form>
                                @endif
                            @endforeach

                            @if (in_array(\App\Enums\ShopOrderStatus::Cancelled, $transitions, true))
                                @if ($cancelling)
                                    <form wire:submit="cancelOrder" class="space-y-2 border-t border-line pt-3">
                                        <x-field :label="__('backoffice.shop.cancel_reason')" name="cancelReason" wire:model="cancelReason" required autofocus />
                                        <div class="flex gap-2">
                                            <x-button type="button" variant="secondary" class="flex-1" wire:click="cancelCancel">{{ __('backoffice.announcements.cancel') }}</x-button>
                                            <x-button type="submit" variant="danger" class="flex-1" target="cancelOrder">{{ __('backoffice.shop.cancel_order') }}<x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading></x-button>
                                        </div>
                                    </form>
                                @else
                                    <x-button variant="danger-outline" class="w-full" wire:click="startCancel">{{ __('backoffice.shop.cancel_order') }}</x-button>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            </x-panel>
        @endif
    </div>
</div>
