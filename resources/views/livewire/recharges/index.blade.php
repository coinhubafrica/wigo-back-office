<div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.recharges.kpi_collected_today')" :value="number_format($kpis['collected_today'], 0, ',', ' ')" unit="FCFA" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.recharges.kpi_pending')" :value="number_format($kpis['pending'], 0, ',', ' ')" tone="warn">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.recharges.kpi_to_replay')" :value="number_format($kpis['to_replay'], 0, ',', ' ')" :alert="$kpis['to_replay'] > 0" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.recharges.kpi_wave_balance')"
                    :value="$kpis['wave_balance'] === null ? __('backoffice.recharges.unknown_balance') : number_format($kpis['wave_balance'], 0, ',', ' ')"
                    :unit="$kpis['wave_balance'] === null ? null : 'FCFA'" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByStatus(null)" :active="$status === null">{{ __('backoffice.recharges.all') }}</x-chip-filter>
            @foreach (\App\Enums\TransactionStatus::cases() as $case)
                <x-chip-filter wire:key="status-{{ $case->value }}" wire:click="filterByStatus('{{ $case->value }}')" :active="$status === $case->value">
                    {{ $case->label() }}
                </x-chip-filter>
            @endforeach
        </div>
        <x-slot:end>
            <x-field :label="__('backoffice.recharges.search_placeholder')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.recharges.search_placeholder')" class="w-80" />
        </x-slot:end>
    </x-toolbar>

    <x-panel class="mt-4" :title="__('backoffice.recharges.journal_title')" :count="$rows->total()" flush>
        <x-table loading="filterByStatus,resetFilters,search,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.recharges.column_transaction') }}</x-th>
                <x-th align="right">{{ __('backoffice.recharges.column_amount') }}</x-th>
                <x-th>{{ __('backoffice.recharges.column_status') }}</x-th>
                <x-th><span class="sr-only">{{ __('backoffice.common.confirm') }}</span></x-th>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="rch-{{ $row->id }}" class="transition-colors hover:bg-surface">
                    <x-td>
                        <b class="block max-w-[260px] truncate text-[13px] text-ink">{{ $row->driver?->fullName() ?? '—' }}</b>
                        <span class="mt-0.5 block max-w-[260px] truncate text-xs text-muted">
                            <span class="font-mono text-[11px]">{{ $row->reference }}</span>
                            — {{ $row->initiated_at->diffForHumans() }}
                        </span>
                    </x-td>
                    <x-td align="right" nowrap class="text-[13px] font-semibold tabular-nums">{{ number_format($row->amount, 0, ',', ' ') }} FCFA</x-td>
                    <x-td><x-badge :classes="$row->status->badgeClasses()">{{ $row->status->label() }}</x-badge></x-td>
                    <x-td align="right" nowrap>
                        @if ($canReconcile && $row->status->isReplayable())
                            <x-button variant="secondary" size="sm" wire:click="confirmReplay('{{ $row->id }}')" target="confirmReplay">
                                {{ __('backoffice.recharges.replay') }}
                            </x-button>
                        @elseif ($canReconcile && $row->status->awaitsCredit())
                            <x-button size="sm" wire:click="confirmMarkCredited('{{ $row->id }}')" target="confirmMarkCredited">
                                {{ __('backoffice.recharges.mark_credited') }}
                            </x-button>
                        @endif
                    </x-td>
                </tr>
            @endforeach

            @if ($rows->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :title="__('backoffice.recharges.no_rows')" :hint="__('backoffice.recharges.no_rows_hint')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">{{ __('backoffice.recharges.reset_filters') }}</x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($rows->hasPages())
                <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>

    {{-- Confirmation en modale plutôt que `wire:confirm` : le dialogue natif
         bloque l'automatisation navigateur. Le bouton est gardé : rejouer
         deux fois un crédit n'est pas une option. --}}
    @if ($pendingConfirmation !== null)
        @php
            $isReplay = $confirmingReplayId !== null;
            $placeholders = [
                'reference' => $pendingConfirmation->reference,
                'amount' => number_format($pendingConfirmation->amount, 0, ',', ' '),
            ];
        @endphp
        <x-confirm close="cancelConfirmation"
                   :action="$isReplay ? 'replay' : 'markCredited'"
                   :title="$isReplay ? __('backoffice.recharges.confirm_replay_title') : __('backoffice.recharges.confirm_mark_credited_title')"
                   :body="$isReplay ? __('backoffice.recharges.confirm_replay_body', $placeholders) : __('backoffice.recharges.confirm_mark_credited_body', $placeholders)"
                   :confirm-label="__('backoffice.recharges.confirm')" />
    @endif
</div>
