<div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.kpi_collected_today') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($kpis['collected_today'], 0, ',', ' ') }} <span class="text-sm font-medium text-muted">FCFA</span></p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.kpi_pending') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($kpis['pending'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.kpi_to_replay') }}</p>
            <p @class([
                'mt-1.5 text-2xl font-semibold',
                'text-err-text' => $kpis['to_replay'] > 0,
                'text-ink' => $kpis['to_replay'] === 0,
            ])>{{ number_format($kpis['to_replay'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.kpi_wave_balance') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">
                @if ($kpis['wave_balance'] === null)
                    {{ __('backoffice.recharges.unknown_balance') }}
                @else
                    {{ number_format($kpis['wave_balance'], 0, ',', ' ') }} <span class="text-sm font-medium text-muted">FCFA</span>
                @endif
            </p>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-1.5">
            {{-- `aria-pressed` : la sélection est signalée par la couleur seule,
                 invisible pour un lecteur d'écran sans cet état. --}}
            <button wire:click="filterByStatus(null)"
                    aria-pressed="{{ $status === null ? 'true' : 'false' }}"
                    @class([
                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                        'border-primary bg-primary-tint text-primary-text' => $status === null,
                        'border-line bg-card text-muted hover:border-primary' => $status !== null,
                    ])>
                {{ __('backoffice.recharges.all') }}
            </button>
            @foreach (\App\Enums\TransactionStatus::cases() as $case)
                <button wire:click="filterByStatus('{{ $case->value }}')"
                        aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}"
                        @class([
                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                            'border-primary bg-primary-tint text-primary-text' => $status === $case->value,
                            'border-line bg-card text-muted hover:border-primary' => $status !== $case->value,
                        ])>
                    {{ $case->label() }}
                </button>
            @endforeach
        </div>

        <span class="flex-1"></span>

        <input wire:model.live.debounce.400ms="search" type="search"
               placeholder="{{ __('backoffice.recharges.search_placeholder') }}"
               class="w-80 rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="border-b border-line px-5 py-4 text-xs font-extrabold uppercase tracking-wide text-zinc-600">
            {{ __('backoffice.recharges.journal_title') }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-surface">
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.column_transaction') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.column_amount') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.recharges.column_status') }}</th>
                        <th class="border-b border-line px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="rch-{{ $row->id }}">
                            <td class="border-b border-line px-4 py-3">
                                <b class="block max-w-[220px] truncate text-[13px] text-ink">{{ $row->driver?->fullName() ?? '—' }}</b>
                                <div class="mt-0.5 max-w-[220px] truncate text-xs text-muted">
                                    <span class="font-mono text-[11px]">{{ $row->reference }}</span>
                                    — {{ $row->initiated_at->diffForHumans() }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-right text-[13px] font-extrabold text-ok-text">
                                {{ number_format($row->amount, 0, ',', ' ') }} FCFA
                            </td>
                            <td class="border-b border-line px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $row->status->badgeClasses() }}">
                                    {{ $row->status->label() }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-right">
                                @if ($canReconcile && $row->status->isReplayable())
                                    <button wire:click="confirmReplay('{{ $row->id }}')"
                                            class="rounded border border-line bg-card px-3 py-1.5 text-xs font-bold text-ink hover:bg-surface">
                                        {{ __('backoffice.recharges.replay') }}
                                    </button>
                                @elseif ($canReconcile && $row->status->awaitsCredit())
                                    <button wire:click="confirmMarkCredited('{{ $row->id }}')"
                                            class="rounded bg-ok-text px-3 py-1.5 text-xs font-bold text-white hover:opacity-90">
                                        {{ __('backoffice.recharges.mark_credited') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center">
                                <p class="text-sm font-semibold text-ink">{{ __('backoffice.recharges.no_rows') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('backoffice.recharges.no_rows_hint') }}</p>
                                <button wire:click="resetFilters" class="mt-3 rounded border border-line bg-card px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface">
                                    {{ __('backoffice.recharges.reset_filters') }}
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>

    {{-- Modale plutôt que `wire:confirm` : le dialogue natif bloque
         l'automatisation navigateur. --}}
    @if ($pendingConfirmation !== null)
        @php
            $isReplay = $confirmingReplayId !== null;
            $placeholders = [
                'reference' => $pendingConfirmation->reference,
                'amount' => number_format($pendingConfirmation->amount, 0, ',', ' '),
            ];
        @endphp
        <x-modal close="cancelConfirmation" max-width="max-w-sm"
                 :label="$isReplay ? __('backoffice.recharges.confirm_replay_title') : __('backoffice.recharges.confirm_mark_credited_title')">
                <div class="px-5 pb-4 pt-5">
                    <p class="text-sm font-semibold text-ink">
                        {{ $isReplay ? __('backoffice.recharges.confirm_replay_title') : __('backoffice.recharges.confirm_mark_credited_title') }}
                    </p>
                    <p class="mt-1.5 text-sm text-muted">
                        {{ $isReplay
                            ? __('backoffice.recharges.confirm_replay_body', $placeholders)
                            : __('backoffice.recharges.confirm_mark_credited_body', $placeholders) }}
                    </p>
                </div>
                <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                    <button wire:click="cancelConfirmation" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.recharges.cancel') }}
                    </button>
                    <button wire:click="{{ $isReplay ? 'replay' : 'markCredited' }}" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                        {{ __('backoffice.recharges.confirm') }}
                    </button>
                </div>
        </x-modal>
    @endif
</div>
