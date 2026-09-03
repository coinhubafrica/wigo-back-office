{{--
    Détail d'une campagne.

    L'écran est en lecture seule : ce qui a été écrit, à qui, et qui l'a lu.
    Le message vient en premier et en entier — c'est la seule trace de ce que
    les conducteurs ont réellement reçu, et le tronquer ferait perdre le sens
    du reste de la page. Seul un brouillon porte encore une action : l'envoi.
--}}
@php($isDraft = $campaign->status === \App\Enums\CampaignStatus::Draft)
<div class="flex max-w-[860px] flex-col gap-4">
    <x-slot:back>
        <x-back-link :href="route('bo.campaigns')">{{ __('backoffice.campaigns.back_to_list') }}</x-back-link>
    </x-slot:back>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Le message, tel qu'il est lu côté conducteur.                     --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-panel :title="__('backoffice.campaigns.message_section')">
        <x-slot:actions>
            <x-badge :classes="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-badge>
            @if ($isDraft)
                <x-button size="sm" wire:click="confirmSend" target="confirmSend">{{ __('backoffice.campaigns.send') }}</x-button>
            @endif
        </x-slot:actions>

        <h2 class="text-lg font-semibold text-ink">{{ $campaign->title }}</h2>
        <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.message_hint') }}</p>

        {{-- `whitespace-pre-line` : les retours à la ligne saisis dans le
             composeur font partie du message et doivent survivre ici. --}}
        <div class="mt-3 whitespace-pre-line rounded-lg rounded-tl-sm border border-line bg-surface px-4 py-3.5 text-sm leading-relaxed text-ink">{{ $campaign->body }}</div>

        @if ($campaign->deeplink)
            <p class="mt-2.5 flex flex-wrap items-center gap-2">
                <span class="text-xs text-muted">{{ __('backoffice.campaigns.deeplink_label') }}</span>
                <code class="rounded border border-line bg-surface px-2 py-1 font-mono text-[11px] text-ink">{{ $campaign->deeplink }}</code>
            </p>
        @endif
    </x-panel>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Statistiques. Rien n'est parti : un 0 lecture se lirait comme un  --}}
    {{-- échec, alors que la question ne se pose pas encore.               --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <x-kpi-card :label="$isDraft ? __('backoffice.campaigns.stat_recipients_estimate') : __('backoffice.campaigns.stat_recipients')"
                    :value="number_format($isDraft ? ($pending ?? 0) : $delivered, 0, ',', ' ')"
                    :hint="$isDraft ? __('backoffice.campaigns.stat_recipients_estimate_hint') : null" tone="primary" />
        <x-kpi-card :label="__('backoffice.campaigns.stat_read')"
                    :value="$isDraft ? '—' : number_format($read, 0, ',', ' ')"
                    :hint="$isDraft ? __('backoffice.campaigns.not_applicable') : null" tone="ok" />
        <x-kpi-card :label="__('backoffice.campaigns.stat_rate')"
                    :value="($isDraft || $rate === null) ? '—' : number_format($rate, 1, ',', ' ')"
                    :unit="($isDraft || $rate === null) ? null : '%'"
                    :hint="$isDraft ? __('backoffice.campaigns.not_applicable') : null" />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Détails.                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-panel :title="__('backoffice.campaigns.details_section')">
        <x-dl cols="2">
            <x-dl-item :term="__('backoffice.campaigns.detail_status')">
                <x-badge :classes="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-badge>
            </x-dl-item>
            <x-dl-item :term="__('backoffice.campaigns.detail_audience')">
                <span class="flex flex-wrap items-center gap-1.5">
                    <x-badge :classes="$campaign->audience->badgeClasses()">{{ $campaign->audience->label() }}</x-badge>
                    @foreach ($segmentLabels as $label)
                        <x-badge wire:key="segment-label-{{ $loop->index }}">{{ $label }}</x-badge>
                    @endforeach
                </span>
            </x-dl-item>
            <x-dl-item :term="__('backoffice.campaigns.detail_author')">{{ $campaign->createdByUser?->fullName() ?? __('backoffice.campaigns.unknown_author') }}</x-dl-item>
            <x-dl-item :term="__('backoffice.campaigns.detail_created_at')" mono>{{ $campaign->created_at?->format('d/m/Y H:i') ?? '—' }}</x-dl-item>
            <x-dl-item :term="__('backoffice.campaigns.detail_sent_at')" mono>{{ $campaign->sent_at?->format('d/m/Y H:i') ?? '—' }}</x-dl-item>
        </x-dl>
    </x-panel>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Destinataires : les messages déposés font foi.                    --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-panel :title="__('backoffice.campaigns.recipients_section')" flush>
        @unless ($isDraft)
            <x-slot:actions>
                @foreach (['all' => __('backoffice.campaigns.filter_all'), 'read' => __('backoffice.campaigns.filter_read'), 'unread' => __('backoffice.campaigns.filter_unread')] as $value => $label)
                    <x-chip-filter wire:key="recipient-filter-{{ $value }}" wire:click="filterBy('{{ $value }}')" :active="$filter === $value">{{ $label }}</x-chip-filter>
                @endforeach
            </x-slot:actions>
        @endunless

        @if ($isDraft)
            <x-empty-state tone="neutral" :hint="__('backoffice.campaigns.recipients_draft')" />
        @else
            <x-table loading="filterBy,gotoPage,previousPage,nextPage">
                <x-slot:head>
                    <x-th>{{ __('backoffice.campaigns.column_driver') }}</x-th>
                    <x-th>{{ __('backoffice.campaigns.column_phone') }}</x-th>
                    <x-th>{{ __('backoffice.campaigns.column_read_state') }}</x-th>
                </x-slot:head>

                @foreach ($recipients as $message)
                    @php($driver = $message->conversation?->driver)
                    <tr wire:key="recipient-{{ $message->id }}" class="transition-colors hover:bg-surface">
                        <x-td>
                            @if ($driver)
                                <a href="{{ route('bo.drivers.show', $driver) }}" wire:navigate
                                   class="text-[13px] font-semibold text-primary-text hover:underline">{{ $driver->fullName() }}</a>
                            @else
                                <span class="text-[13px] text-muted">—</span>
                            @endif
                        </x-td>
                        <x-td mono muted nowrap>{{ $driver?->phone ?? '—' }}</x-td>
                        <x-td nowrap>
                            @if ($message->read_at)
                                <x-badge tone="ok">{{ __('backoffice.campaigns.read_badge') }}</x-badge>
                                <span class="ml-1.5 text-xs text-muted tabular-nums">{{ $message->read_at->format('d/m/Y H:i') }}</span>
                            @else
                                <x-badge>{{ __('backoffice.campaigns.unread_badge') }}</x-badge>
                            @endif
                        </x-td>
                    </tr>
                @endforeach

                @if ($recipients->isEmpty())
                    <x-slot:empty>
                        <x-empty-state tone="neutral" :title="__('backoffice.campaigns.recipients_none')" :hint="__('backoffice.campaigns.recipients_none_hint')" />
                    </x-slot:empty>
                @endif

                @if ($recipients->hasPages())
                    <x-slot:footer>{{ $recipients->links() }}</x-slot:footer>
                @endif
            </x-table>
        @endif
    </x-panel>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Envoi : un brouillon seulement.                                   --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($isDraft)
        <x-banner tone="info" :title="__('backoffice.campaigns.send_section')">
            {{ __('backoffice.campaigns.send_section_hint') }}
            <x-slot:actions>
                <x-button size="sm" wire:click="confirmSend" target="confirmSend">{{ __('backoffice.campaigns.send') }}</x-button>
            </x-slot:actions>
        </x-banner>

        @if ($confirmingSend)
            <x-confirm close="cancelSend" action="send"
                       :title="__('backoffice.campaigns.confirm_send_title')"
                       :body="__('backoffice.campaigns.confirm_send_body')"
                       :confirm-label="__('backoffice.campaigns.send')"
                       :loading="__('backoffice.common.sending')">
                {{-- `confirmingCount` et non `pending` : le nombre est figé
                     à l'ouverture, pour ne pas bouger entre la lecture et
                     le clic. --}}
                <p class="mt-2 text-xl font-semibold text-primary-text tabular-nums">
                    {{ trans_choice('backoffice.campaigns.recipient_count', $confirmingCount ?? 0, ['count' => number_format($confirmingCount ?? 0, 0, ',', ' ')]) }}
                </p>
            </x-confirm>
        @endif
    @endif
</div>
