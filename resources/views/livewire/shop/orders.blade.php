<div>
    <div class="flex flex-wrap items-center gap-2">
        <button wire:click="filterByStatus(null)"
                @class(['rounded-full border px-3.5 py-1.5 text-xs font-semibold', 'border-primary bg-primary-tint text-primary-text' => $status === null, 'border-line text-muted hover:bg-surface' => $status !== null])>
            {{ __('backoffice.shop.all_statuses') }}
        </button>
        @foreach ($statuses as $orderStatus)
            <button wire:key="status-{{ $orderStatus->value }}" wire:click="filterByStatus('{{ $orderStatus->value }}')"
                    @class(['rounded-full border px-3.5 py-1.5 text-xs font-semibold', 'border-primary bg-primary-tint text-primary-text' => $status === $orderStatus->value, 'border-line text-muted hover:bg-surface' => $status !== $orderStatus->value])>
                {{ $orderStatus->label() }}
            </button>
        @endforeach

        <span class="flex-1"></span>

        <div class="flex items-center gap-2 rounded border border-line bg-card px-3 py-1.5">
            <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.400ms="search" type="search" placeholder="{{ __('backoffice.shop.col_reference') }} / {{ __('backoffice.shop.col_driver') }}"
                   class="w-56 border-0 p-0 text-sm focus:outline-none focus:ring-0">
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <div class="overflow-hidden rounded border border-line bg-card">
            <div class="border-b border-line px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.orders_title') }}</p>
            </div>

            @if ($orders->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-muted">{{ __('backoffice.shop.orders_none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-surface">
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_reference') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_driver') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_items') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_total') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_mode') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_status') }}</th>
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                <tr wire:key="order-{{ $order->id }}" wire:click="select('{{ $order->id }}')"
                                    @class(['cursor-pointer border-b border-line last:border-0 hover:bg-surface', 'bg-primary-tint' => $selected === $order->id])>
                                    <td class="px-4 py-3 font-mono text-xs font-semibold text-ink">{{ $order->reference }}</td>
                                    <td class="px-4 py-3 text-sm text-ink">{{ $order->driver->first_name }} {{ $order->driver->last_name }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $order->items_count }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold text-ink">{{ number_format($order->total_amount, 0, ',', ' ') }} FCFA</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $order->fulfilment_mode->label() }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->status->badgeClasses() }}">{{ $order->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $order->ordered_at->diffForHumans(short: true) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-line px-4 py-3">{{ $orders->links() }}</div>
            @endif
        </div>

        <div class="rounded border border-line bg-card">
            @if ($selectedOrder === null)
                <p class="px-6 py-12 text-center text-sm text-muted">{{ __('backoffice.shop.select_order') }}</p>
            @else
                <div class="flex items-center gap-3 border-b border-line px-4 py-3">
                    <p class="font-mono text-sm font-semibold text-ink">{{ $selectedOrder->reference }}</p>
                    <span class="flex-1"></span>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $selectedOrder->status->badgeClasses() }}">{{ $selectedOrder->status->label() }}</span>
                </div>

                <div class="space-y-4 px-4 py-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_driver') }}</p>
                        <p class="mt-1 text-sm text-ink">{{ $selectedOrder->driver->first_name }} {{ $selectedOrder->driver->last_name }} · {{ $selectedOrder->driver->phone }}</p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_items') }}</p>
                        <ul class="mt-1 space-y-1">
                            @foreach ($selectedOrder->items as $item)
                                <li wire:key="item-{{ $item->id }}" class="flex items-baseline gap-2 text-sm text-ink">
                                    <span class="flex-1">{{ $item->product_name }} ×{{ $item->quantity }}</span>
                                    <span class="font-semibold">{{ number_format($item->line_total, 0, ',', ' ') }} FCFA</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 flex items-baseline gap-2 border-t border-line pt-2 text-sm">
                            <span class="flex-1 font-semibold text-ink">{{ __('backoffice.shop.col_total') }}</span>
                            <span class="font-semibold text-ok-text">{{ number_format($selectedOrder->total_amount, 0, ',', ' ') }} FCFA</span>
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.col_mode') }}</p>
                        <p class="mt-1 text-sm text-ink">{{ $selectedOrder->fulfilment_mode->label() }}</p>
                        @if ($selectedOrder->fulfilment_mode === $pickupMode)
                            @if ($selectedOrder->delivery?->pickupPoint !== null)
                                <p class="text-sm text-muted">{{ $selectedOrder->delivery->pickupPoint->name }} — {{ $selectedOrder->delivery->pickupPoint->address }}</p>
                            @endif
                            @if ($selectedOrder->pickup_code !== null)
                                <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.shop.pickup_code') }}</p>
                                <p class="font-mono text-lg font-semibold tracking-widest text-ink">{{ $selectedOrder->pickup_code }}</p>
                            @endif
                        @else
                            @if ($selectedOrder->delivery !== null)
                                <p class="text-sm text-muted">{{ $selectedOrder->delivery->latitude }}, {{ $selectedOrder->delivery->longitude }}</p>
                                <p class="text-sm text-muted">{{ __('backoffice.shop.contact') }} : {{ $selectedOrder->delivery->contact_phone }}</p>
                            @endif
                        @endif
                    </div>

                    @if ($selectedOrder->cancellation_reason !== null)
                        <div class="rounded border border-err-text/30 bg-err-bg px-3 py-2">
                            <p class="text-sm text-err-text">{{ $selectedOrder->cancellation_reason }}</p>
                        </div>
                    @endif

                    @if ($canManageStock && $transitions !== [])
                        <div class="space-y-2 border-t border-line pt-4">
                            @foreach ($transitions as $transition)
                                @if ($transition === \App\Enums\ShopOrderStatus::Ready)
                                    <button wire:click="markReady" class="w-full rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                                        {{ __('backoffice.shop.mark_ready') }}
                                    </button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::OutForDelivery)
                                    <button wire:click="markDispatched" class="w-full rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                                        {{ __('backoffice.shop.mark_dispatched') }}
                                    </button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::Delivered)
                                    <button wire:click="markDelivered" class="w-full rounded bg-ok-text px-3.5 py-2 text-sm font-semibold text-white hover:opacity-90">
                                        {{ __('backoffice.shop.mark_delivered') }}
                                    </button>
                                @elseif ($transition === \App\Enums\ShopOrderStatus::Collected)
                                    <form wire:submit="markCollected" class="space-y-2">
                                        <label for="pickupCode" class="block text-xs font-semibold text-muted">{{ __('backoffice.shop.pickup_code_prompt') }}</label>
                                        <input wire:model="pickupCode" id="pickupCode" type="text" inputmode="numeric" maxlength="6"
                                               class="block w-full rounded border border-input px-3 py-2 font-mono text-sm tracking-widest focus:border-primary focus:outline-none">
                                        @error('pickupCode') <p class="text-sm text-err-text">{{ $message }}</p> @enderror
                                        <button type="submit" class="w-full rounded bg-ok-text px-3.5 py-2 text-sm font-semibold text-white hover:opacity-90">
                                            {{ __('backoffice.shop.mark_collected') }}
                                        </button>
                                    </form>
                                @endif
                            @endforeach

                            @if (in_array(\App\Enums\ShopOrderStatus::Cancelled, $transitions, true))
                                @if ($cancelling)
                                    <form wire:submit="cancelOrder" class="space-y-2 border-t border-line pt-3">
                                        <label for="cancelReason" class="block text-xs font-semibold text-muted">{{ __('backoffice.shop.cancel_reason') }}</label>
                                        <input wire:model="cancelReason" id="cancelReason" type="text"
                                               class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary focus:outline-none">
                                        @error('cancelReason') <p class="text-sm text-err-text">{{ $message }}</p> @enderror
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="cancelCancel" class="flex-1 rounded border border-line px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                                                {{ __('backoffice.announcements.cancel') }}
                                            </button>
                                            <button type="submit" class="flex-1 rounded bg-err-text px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                                                {{ __('backoffice.shop.cancel_order') }}
                                            </button>
                                        </div>
                                    </form>
                                @else
                                    <button wire:click="startCancel" class="w-full rounded border border-line px-3.5 py-2 text-sm font-semibold text-err-text hover:border-err-text hover:bg-err-bg">
                                        {{ __('backoffice.shop.cancel_order') }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
