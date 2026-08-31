<div>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.declared_this_month') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($totals['declared'], 0, ',', ' ') }} <span class="text-sm font-medium text-muted">FCFA</span></p>
            <p class="mt-0.5 text-xs text-muted">{{ $periodLabel }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.drivers_declaring') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($totals['drivers_declaring'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.behind') }}</p>
            <p @class([
                'mt-1.5 text-2xl font-semibold',
                'text-err-text' => $totals['behind'] > 0,
                'text-ink' => $totals['behind'] === 0,
            ])>{{ number_format($totals['behind'], 0, ',', ' ') }}</p>
        </div>
    </div>

    {{-- Le back-office ne valide rien : il constate. --}}
    <p class="mt-4 rounded border border-dashed border-warn-text/30 bg-warn-bg px-4 py-2.5 text-xs font-medium text-warn-text">
        {{ __('backoffice.cnps.declarative_notice') }}
    </p>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-1.5">
            {{-- `aria-pressed` : la sélection est signalée par la couleur seule,
                 invisible pour un lecteur d'écran sans cet état. --}}
            <button wire:click="filterByState(null)"
                    aria-pressed="{{ $state === null ? 'true' : 'false' }}"
                    @class([
                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                        'border-primary bg-primary-tint text-primary-text' => $state === null,
                        'border-line bg-card text-muted hover:border-primary' => $state !== null,
                    ])>
                {{ __('backoffice.cnps.all') }}
            </button>
            @foreach (\App\Enums\CnpsMonthStatus::cases() as $case)
                <button wire:click="filterByState('{{ $case->value }}')"
                        aria-pressed="{{ $state === $case->value ? 'true' : 'false' }}"
                        @class([
                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                            'border-primary bg-primary-tint text-primary-text' => $state === $case->value,
                            'border-line bg-card text-muted hover:border-primary' => $state !== $case->value,
                        ])>
                    {{ $case->label() }}
                </button>
            @endforeach
        </div>

        <span class="flex-1"></span>

        <select wire:model.live="period"
                class="rounded border border-input bg-card px-3 py-2 text-sm text-ink focus:border-primary">
            @foreach ($periodOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <input wire:model.live.debounce.400ms="search" type="search"
               placeholder="{{ __('backoffice.cnps.search_placeholder') }}"
               class="w-80 rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-surface">
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.column_driver') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.column_declared') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.column_state') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.cnps.column_payments') }}</th>
                        <th class="border-b border-line px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $declared = (int) $row->period_declared;
                            $reference = $row->period_reference === null ? null : (int) $row->period_reference;
                            $status = $this->statusOf($declared, $reference);
                        @endphp
                        <tr wire:key="cnps-{{ $row->id }}">
                            <td class="border-b border-line px-4 py-2.5">
                                <b class="text-[13px] text-ink">{{ $row->fullName() }}</b>
                                <span class="ml-2 font-mono text-[11px] text-muted">{{ $row->yango_id ?? '—' }}</span>
                            </td>
                            <td class="border-b border-line px-4 py-2.5 text-right text-[13px]">
                                <b @class([
                                    'font-semibold',
                                    'text-ink' => $declared > 0,
                                    'text-muted' => $declared === 0,
                                ])>{{ $declared > 0 ? number_format($declared, 0, ',', ' ') : '—' }}</b>
                                <span class="text-muted"> / {{ $reference === null ? '—' : number_format($reference, 0, ',', ' ') }}</span>
                            </td>
                            <td class="border-b border-line px-4 py-2.5">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                    'bg-ok-bg text-ok-text' => $status === \App\Enums\CnpsMonthStatus::Paid,
                                    'bg-warn-bg text-warn-text' => $status === \App\Enums\CnpsMonthStatus::Partial,
                                    'bg-err-bg text-err-text' => $status === \App\Enums\CnpsMonthStatus::Late,
                                    'bg-neutral-bg text-neutral-text' => $status === \App\Enums\CnpsMonthStatus::Pending,
                                ])>{{ $status->label() }}</span>
                            </td>
                            <td class="border-b border-line px-4 py-2.5 text-[13px] text-muted">
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
                            </td>
                            <td class="border-b border-line px-4 py-2.5 text-right">
                                <a href="{{ route('bo.drivers.show', $row) }}" wire:navigate
                                   class="text-xs font-semibold text-primary-text hover:underline">{{ __('backoffice.cnps.view_driver') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">
                                {{ __('backoffice.cnps.no_rows') }}
                                <button wire:click="resetFilters" class="ml-1 font-semibold text-primary-text hover:underline">{{ __('backoffice.cnps.reset_filters') }}</button>
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
</div>
