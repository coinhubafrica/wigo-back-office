<div>
    <div class="grid gap-3 sm:grid-cols-3">
        <x-kpi-card :label="__('backoffice.cnps.declared_this_month')" :value="number_format($totals['declared'], 0, ',', ' ')" unit="FCFA" :hint="$periodLabel" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.cnps.drivers_declaring')" :value="number_format($totals['drivers_declaring'], 0, ',', ' ')" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.cnps.behind')" :value="number_format($totals['behind'], 0, ',', ' ')" :alert="$totals['behind'] > 0">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    {{-- Le back-office ne valide rien : il constate. --}}
    <x-banner tone="warn" class="mt-4">{{ __('backoffice.cnps.declarative_notice') }}</x-banner>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByState(null)" :active="$state === null">{{ __('backoffice.cnps.all') }}</x-chip-filter>
            @foreach (\App\Enums\CnpsMonthStatus::cases() as $case)
                <x-chip-filter wire:key="state-{{ $case->value }}" wire:click="filterByState('{{ $case->value }}')" :active="$state === $case->value">
                    {{ $case->label() }}
                </x-chip-filter>
            @endforeach
        </div>

        <x-slot:end>
            <x-field :label="__('backoffice.cnps.current_month')" name="period" type="select" label-hidden wire:model.live="period" class="w-44">
                @foreach ($periodOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-field>
            <x-field :label="__('backoffice.cnps.search_placeholder')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.cnps.search_placeholder')" class="w-80" />
        </x-slot:end>
    </x-toolbar>

    <x-panel class="mt-4" flush>
        <x-table loading="filterByState,resetFilters,search,period,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.cnps.column_driver') }}</x-th>
                <x-th align="right">{{ __('backoffice.cnps.column_declared') }}</x-th>
                <x-th>{{ __('backoffice.cnps.column_state') }}</x-th>
                <x-th>{{ __('backoffice.cnps.column_payments') }}</x-th>
                <x-th><span class="sr-only">{{ __('backoffice.cnps.view_driver') }}</span></x-th>
            </x-slot:head>

            @foreach ($rows as $row)
                @php
                    $declared = (int) $row->period_declared;
                    $reference = $row->period_reference === null ? null : (int) $row->period_reference;
                    $status = $this->statusOf($declared, $reference);
                @endphp
                <tr wire:key="cnps-{{ $row->id }}" class="transition-colors hover:bg-surface">
                    <x-td>
                        <b class="text-[13px] text-ink">{{ $row->fullName() }}</b>
                        <span class="ml-2 font-mono text-[11px] text-muted">{{ $row->yango_id ?? '—' }}</span>
                    </x-td>
                    <x-td align="right" nowrap>
                        <b @class(['font-semibold tabular-nums', 'text-ink' => $declared > 0, 'text-muted' => $declared === 0])>{{ $declared > 0 ? number_format($declared, 0, ',', ' ') : '—' }}</b>
                        <span class="text-muted tabular-nums"> / {{ $reference === null ? '—' : number_format($reference, 0, ',', ' ') }}</span>
                    </x-td>
                    <x-td><x-badge :classes="$status->badgeClasses()">{{ $status->label() }}</x-badge></x-td>
                    <x-td muted class="text-[13px]">
                        {{ trans_choice('backoffice.cnps.payments_count', $row->period_payments, ['count' => $row->period_payments]) }}
                        @if ($row->period_proofs > 0)
                            {{-- Trombone en SVG et non en emoji : le rendu
                                 variait selon l'OS et `title` seul n'est pas
                                 un nom accessible fiable. --}}
                            <svg class="ml-1.5 inline-block size-3.5 align-text-bottom text-muted" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                 role="img" aria-label="{{ __('backoffice.cnps.proof_available') }}">
                                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                            </svg>
                        @endif
                    </x-td>
                    <x-td align="right" nowrap>
                        <a href="{{ route('bo.drivers.show', $row) }}" wire:navigate
                           class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.cnps.view_driver') }}</a>
                    </x-td>
                </tr>
            @endforeach

            @if ($rows->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :hint="__('backoffice.cnps.no_rows')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">{{ __('backoffice.cnps.reset_filters') }}</x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($rows->hasPages())
                <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>
</div>
