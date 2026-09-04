@php
    use App\Enums\CnpsMonthStatus;

    $currentCnps = $cnps['current'];
    $currentCnpsStatus = CnpsMonthStatus::from($currentCnps['status']);
@endphp

{{-- Pleine largeur : la fiche est faite de tableaux (quatre onglets), qu'un
     cadre étroit obligeait à défiler latéralement. Le centrage et la borne à
     1440 px sont déjà portés par `layouts.app`. --}}
<div class="flex flex-col gap-4">
    <x-slot:back>
        <x-back-link :href="route(\App\Enums\BackOfficeModule::Drivers->route())">{{ __('backoffice.drivers.back_to_list') }}</x-back-link>
    </x-slot:back>

    {{-- Identité, véhicule et permis d'un seul bloc : l'ancien panneau
         « Identité & véhicule » répétait sous la carte ce qu'elle disait déjà. --}}
    <x-panel>
        <div class="flex flex-wrap items-start gap-3.5">
            <x-avatar size="lg" :initials="$driver->initials()"
                      :src="$driver->photo_url ? route('bo.drivers.photo', $driver) : null" />
            <div class="min-w-0 flex-1">
                <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-ink">
                    <span class="truncate">{{ $driver->fullName() }}</span>
                    <x-badge :classes="$driver->status->badgeClasses()">{{ $driver->status->label() }}</x-badge>
                </h2>
                <p class="mt-0.5 text-sm text-muted">
                    <span class="font-mono">{{ $driver->yango_id ?? '—' }}</span>
                    <span aria-hidden="true"> · </span>
                    <span class="font-mono">{{ $driver->phone }}</span>
                    <span aria-hidden="true"> · </span>
                    {{ __('backoffice.drivers.license_number') }}
                    <span class="font-mono">{{ $driver->license_number ?? '—' }}</span>
                </p>
                <p class="mt-1 flex items-center gap-1.5 text-sm text-muted">
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5h14l2 5v3a2 2 0 0 1-2 2M7 17v2M17 17v2"/></svg>
                    @if ($driver->vehicle)
                        {{ implode(' · ', array_filter([$driver->vehicle->description(), $driver->vehicle->plate_number])) }}
                    @else
                        {{ __('backoffice.drivers.no_vehicle') }}
                    @endif
                </p>
            </div>

            {{-- Suspendre ouvre un parcours destructeur (liseré rouge) ; la
                 confirmation, elle, est le bouton plein. --}}
            @if ($driver->isSuspended())
                <x-button wire:click="confirmReactivate" target="confirmReactivate">{{ __('backoffice.drivers.reactivate') }}</x-button>
            @elseif (! $showSuspendForm)
                <x-button variant="danger-outline" wire:click="$toggle('showSuspendForm')">{{ __('backoffice.drivers.suspend') }}</x-button>
            @endif
        </div>
    </x-panel>

    @if ($driver->isSuspended())
        <x-banner tone="warn" :title="__('backoffice.drivers.suspension_reason')">
            {{ $driver->suspension_reason }}
        </x-banner>
    @elseif ($showSuspendForm)
        <x-panel :title="__('backoffice.drivers.suspend')">
            <form id="driver-suspend" wire:submit="suspend">
                <x-field :label="__('backoffice.drivers.suspension_reason')" name="suspensionReason"
                         wire:model="suspensionReason" required autofocus />
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2.5">
                    <x-button variant="secondary" wire:click="$toggle('showSuspendForm')">{{ __('backoffice.drivers.cancel') }}</x-button>
                    <x-button variant="danger" type="submit" form="driver-suspend" target="suspend">
                        {{ __('backoffice.drivers.confirm_suspend') }}
                        <x-slot:loading>{{ __('backoffice.common.working') }}</x-slot:loading>
                    </x-button>
                </div>
            </x-slot:footer>
        </x-panel>
    @endif

    {{-- Les trois indicateurs qui disent l'état du conducteur. Les courses de
         la semaine attendent encore la synchronisation Fleet : pas de carte
         plutôt qu'un tiret de plus (cf. .ai/rules/drivers.md). --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <x-kpi-card :label="__('backoffice.drivers.yango_balance')"
                    :value="$driver->yango_balance === null ? '—' : number_format($driver->yango_balance, 0, ',', ' ')"
                    :unit="$driver->yango_balance === null ? null : 'FCFA'" />
        <x-kpi-card :label="__('backoffice.drivers.cnps_this_month')"
                    :value="$currentCnps['declared_amount'] > 0 ? number_format($currentCnps['declared_amount'], 0, ',', ' ') : '—'"
                    :unit="$currentCnps['declared_amount'] > 0 ? 'FCFA' : null"
                    :hint="$currentCnpsStatus->label().' · '.$currentCnps['label']"
                    :alert="$currentCnpsStatus === CnpsMonthStatus::Late" />
        <x-kpi-card :label="__('backoffice.drivers.open_requests')" :value="number_format($openRequestCount)"
                    :alert="$openRequestCount > 0" />
    </div>


    {{-- L'activité du conducteur, les quatre natures côte à côte : un agent au
         téléphone voit d'un coup une commande en cours *et* une recharge en
         échec, sans cliquer. Chaque panneau est borné à cinq lignes et renvoie
         vers son module pour l'historique complet. --}}
    <div class="grid items-start gap-4 xl:grid-cols-2">
        <x-panel flush :title="__('backoffice.drivers.panel_requests')" :count="$openRequestCount > 0 ? $openRequestCount : null">
            <x-slot:actions>
                <a href="{{ route('bo.support-requests') }}" wire:navigate
                   class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.drivers.see_all') }}</a>
            </x-slot:actions>

            <x-table>
                <x-slot:head>
                    <x-th class="w-20">{{ __('backoffice.drivers.col_reference') }}</x-th>
                    <x-th>{{ __('backoffice.drivers.col_subject') }}</x-th>
                    <x-th class="w-28">{{ __('backoffice.drivers.col_status') }}</x-th>
                    <x-th class="w-24">{{ __('backoffice.drivers.col_date') }}</x-th>
                </x-slot:head>
                @foreach ($requests as $request)
                    <tr wire:key="request-{{ $request->id }}" class="transition-colors hover:bg-surface">
                        <x-td mono nowrap>#{{ $request->number }}</x-td>
                        <x-td><span class="line-clamp-1">{{ $request->subject ?? $request->category->label() }}</span></x-td>
                        <x-td><x-badge :classes="$request->status->badgeClasses()">{{ $request->status->label() }}</x-badge></x-td>
                        <x-td nowrap muted>{{ $request->created_at?->translatedFormat('j M') ?? '—' }}</x-td>
                    </tr>
                @endforeach
                @if ($requests->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="ok" :title="__('backoffice.drivers.no_requests')" />
                    </x-slot:empty>
                @endif
            </x-table>
        </x-panel>

        <x-panel flush :title="__('backoffice.drivers.panel_orders')">
            <x-slot:actions>
                <a href="{{ route('bo.shop-orders') }}" wire:navigate
                   class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.drivers.see_all') }}</a>
            </x-slot:actions>

            <x-table>
                <x-slot:head>
                    <x-th>{{ __('backoffice.drivers.col_reference') }}</x-th>
                    <x-th align="right" class="w-32">{{ __('backoffice.drivers.col_amount') }}</x-th>
                    <x-th class="w-28">{{ __('backoffice.drivers.col_status') }}</x-th>
                    <x-th class="w-24">{{ __('backoffice.drivers.col_date') }}</x-th>
                </x-slot:head>
                @foreach ($orders as $order)
                    <tr wire:key="order-{{ $order->id }}" class="transition-colors hover:bg-surface">
                        <x-td mono nowrap>{{ $order->reference }}</x-td>
                        <x-td align="right" nowrap><b class="font-semibold tabular-nums">{{ number_format($order->total_amount, 0, ',', ' ') }} FCFA</b></x-td>
                        <x-td><x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge></x-td>
                        <x-td nowrap muted>{{ $order->ordered_at->translatedFormat('j M') }}</x-td>
                    </tr>
                @endforeach
                @if ($orders->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :title="__('backoffice.drivers.no_orders')" />
                    </x-slot:empty>
                @endif
            </x-table>
        </x-panel>

        <x-panel flush :title="__('backoffice.drivers.panel_topups')">
            <x-slot:actions>
                <a href="{{ route('bo.recharges') }}" wire:navigate
                   class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.drivers.see_all') }}</a>
            </x-slot:actions>

            <x-table>
                <x-slot:head>
                    <x-th>{{ __('backoffice.drivers.col_reference') }}</x-th>
                    <x-th align="right" class="w-32">{{ __('backoffice.drivers.col_amount') }}</x-th>
                    <x-th class="w-28">{{ __('backoffice.drivers.col_status') }}</x-th>
                    <x-th class="w-24">{{ __('backoffice.drivers.col_date') }}</x-th>
                </x-slot:head>
                @foreach ($topups as $topup)
                    <tr wire:key="topup-{{ $topup->id }}" class="transition-colors hover:bg-surface">
                        <x-td mono nowrap>{{ $topup->reference }}</x-td>
                        <x-td align="right" nowrap><b class="font-semibold tabular-nums">{{ number_format($topup->amount, 0, ',', ' ') }} FCFA</b></x-td>
                        <x-td><x-badge :classes="$topup->status->badgeClasses()">{{ $topup->status->label() }}</x-badge></x-td>
                        <x-td nowrap muted>{{ $topup->initiated_at->translatedFormat('j M') }}</x-td>
                    </tr>
                @endforeach
                @if ($topups->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :title="__('backoffice.drivers.no_topups')" />
                    </x-slot:empty>
                @endif
            </x-table>
        </x-panel>

        {{-- Suivi CNPS : le même relevé que celui vu par le conducteur dans
             l'application, pour qu'agent et conducteur parlent des mêmes
             chiffres. Rien à valider ici : la déclaration fait foi telle quelle
             (cf. .ai/rules/cnps.md). --}}
        <x-panel flush :title="__('backoffice.drivers.panel_cnps')">
            <x-slot:actions>
                @if ($cnps['reference'] === null)
                    <span class="text-xs text-muted">{{ __('backoffice.cnps.reference_none') }}</span>
                @else
                    <span class="text-xs text-muted">
                        {{ __('backoffice.cnps.reference_amount') }}
                        <b class="text-ink tabular-nums">{{ number_format($cnps['reference']['amount'], 0, ',', ' ') }} FCFA</b>
                    </span>
                @endif
                <a href="{{ route('bo.cnps') }}" wire:navigate
                   class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.drivers.see_all') }}</a>
            </x-slot:actions>

            <div class="border-b border-line px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.current_month') }} — {{ $currentCnps['label'] }}</p>
                <div class="mt-2 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <p class="text-2xl font-semibold text-ink tabular-nums">
                        {{ number_format($currentCnps['declared_amount'], 0, ',', ' ') }}
                        <span class="text-sm font-medium text-muted">
                            / {{ $currentCnps['reference_amount'] === null ? '—' : number_format($currentCnps['reference_amount'], 0, ',', ' ') }} FCFA
                        </span>
                    </p>
                    <x-badge :classes="$currentCnpsStatus->badgeClasses()">{{ $currentCnpsStatus->label() }}</x-badge>
                    @if ($currentCnps['remaining'] > 0)
                        <span class="text-xs text-muted">
                            {{ __('backoffice.cnps.remaining') }} :
                            <b class="text-ink tabular-nums">{{ number_format($currentCnps['remaining'], 0, ',', ' ') }} FCFA</b>
                        </span>
                    @endif
                </div>
                @if ($currentCnps['reference_amount'] !== null)
                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-bg"
                         role="progressbar" aria-valuenow="{{ (int) $currentCnps['progress'] }}"
                         aria-valuemin="0" aria-valuemax="100"
                         aria-label="{{ __('backoffice.cnps.current_month') }}">
                        <div class="h-full rounded-full bg-ok-text transition-[width]" style="width: {{ $currentCnps['progress'] }}%"></div>
                    </div>
                @endif
            </div>

            <x-table>
                <x-slot:head>
                    <x-th class="w-32">{{ __('backoffice.cnps.column_month') }}</x-th>
                    <x-th align="right" class="w-40">{{ __('backoffice.cnps.column_declared') }}</x-th>
                    <x-th class="w-24">{{ __('backoffice.cnps.column_state') }}</x-th>
                    <x-th>{{ __('backoffice.cnps.column_payments') }}</x-th>
                </x-slot:head>
                @foreach ($cnps['history'] as $month)
                    @php $monthStatus = CnpsMonthStatus::from($month['status']); @endphp
                    <tr wire:key="cnps-month-{{ $month['label'] }}" class="transition-colors hover:bg-surface">
                        <x-td nowrap>{{ $month['label'] }}</x-td>
                        <x-td align="right" nowrap>
                            <b @class(['font-semibold tabular-nums', 'text-ink' => $month['declared_amount'] > 0, 'text-muted' => $month['declared_amount'] === 0])>{{ $month['declared_amount'] > 0 ? number_format($month['declared_amount'], 0, ',', ' ') : '—' }}</b>
                            <span class="text-muted tabular-nums"> / {{ $month['reference_amount'] === null ? '—' : number_format($month['reference_amount'], 0, ',', ' ') }}</span>
                        </x-td>
                        <x-td><x-badge :classes="$monthStatus->badgeClasses()">{{ $monthStatus->label() }}</x-badge></x-td>
                        <x-td muted class="text-[11px]">
                            @foreach ($month['declarations'] as $declaration)
                                <span class="mr-3 whitespace-nowrap">
                                    {{ number_format($declaration['declared_amount'], 0, ',', ' ') }}
                                    {{ __('backoffice.cnps.payment_on', ['date' => \Illuminate\Support\Carbon::parse($declaration['payment_date'])->translatedFormat('j M')]) }}
                                    @if ($declaration['has_proof'])
                                        {{-- Trombone en SVG et non en emoji : le rendu
                                             variait selon l'OS et `title` seul n'est pas
                                             un nom accessible fiable. --}}
                                        <svg class="inline-block size-3 align-text-bottom text-muted" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                             role="img" aria-label="{{ __('backoffice.cnps.with_proof') }}">
                                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                        </svg>
                                    @endif
                                </span>
                            @endforeach
                        </x-td>
                    </tr>
                @endforeach
            </x-table>
        </x-panel>
    </div>

    @if ($confirmingReactivation)
        <x-confirm close="cancelReactivate" action="reactivate"
                   :title="__('backoffice.drivers.reactivate')"
                   :body="__('backoffice.drivers.confirm_reactivate')"
                   :confirm-label="__('backoffice.drivers.reactivate')" />
    @endif
</div>
