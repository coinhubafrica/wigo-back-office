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
            {{-- Dupliquer plutôt que renvoyer : l'original reste une trace,
                 la copie s'édite puis part comme un envoi neuf. --}}
            @if ($canManage)
                <x-button size="sm" variant="secondary" wire:click="duplicate" target="duplicate">
                    {{ __('backoffice.campaigns.duplicate') }}
                </x-button>
            @endif
            @if ($isDraft)
                <x-button size="sm" wire:click="confirmSend" target="confirmSend">{{ __('backoffice.campaigns.send') }}</x-button>
            @endif
        </x-slot:actions>

        <h2 class="text-lg font-semibold text-ink">{{ $campaign->title }}</h2>
        <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.message_hint') }}</p>

        {{-- L'image au-dessus du texte, comme dans le fil du conducteur. Elle
             vit sur un disque privé : elle passe par la route protégée, jamais
             par son chemin de stockage. --}}
        @if ($campaign->hasImage())
            <img src="{{ route('bo.campaigns.image', $campaign) }}"
                 alt="{{ __('backoffice.campaigns.image_alt', ['title' => $campaign->title]) }}"
                 class="mt-3 max-h-80 rounded-lg border border-line object-contain">
        @endif

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

                {{-- La puce des échecs n'apparaît que s'il y en a : un compteur
                     à zéro en permanence finirait par ne plus se lire. --}}
                @if ($failed > 0)
                    <x-chip-filter wire:key="recipient-filter-failed" wire:click="filterBy('failed')"
                                   :active="$filter === 'failed'" tone="danger" :count="$failed">
                        {{ __('backoffice.campaigns.filter_failed') }}
                    </x-chip-filter>

                    @if ($canSend)
                        <x-button size="sm" variant="secondary" wire:click="confirmReplayAll" target="confirmReplayAll">
                            {{ __('backoffice.campaigns.replay_all') }}
                        </x-button>
                    @endif
                @endif
            </x-slot:actions>
        @endunless

        @if ($isDraft)
            <x-empty-state tone="neutral" :hint="__('backoffice.campaigns.recipients_draft')" />
        @else
            <x-table loading="filterBy,gotoPage,previousPage,nextPage">
                <x-slot:head>
                    <x-th>{{ __('backoffice.campaigns.column_driver') }}</x-th>
                    <x-th>{{ __('backoffice.campaigns.column_phone') }}</x-th>
                    <x-th>{{ __('backoffice.campaigns.column_delivery') }}</x-th>
                    <x-th>{{ __('backoffice.campaigns.column_read_state') }}</x-th>
                    <x-th><span class="sr-only">{{ __('backoffice.campaigns.replay') }}</span></x-th>
                </x-slot:head>

                @foreach ($recipients as $recipient)
                    @php($driver = $recipient->driver)
                    <tr wire:key="recipient-{{ $recipient->id }}" class="transition-colors hover:bg-surface">
                        <x-td>
                            @if ($driver)
                                <a href="{{ route('bo.drivers.show', $driver) }}" wire:navigate
                                   class="text-[13px] font-semibold text-primary-text hover:underline">{{ $driver->fullName() }}</a>
                            @else
                                <span class="text-[13px] text-muted">—</span>
                            @endif
                        </x-td>
                        <x-td mono muted nowrap>{{ $driver?->phone ?? '—' }}</x-td>
                        {{-- Remise : l'état de l'envoi chez ce conducteur. Une
                             ligne en échec porte sa raison, sans quoi il n'y
                             aurait rien à faire de l'information. --}}
                        <x-td>
                            <x-badge :classes="$recipient->status->badgeClasses()">{{ $recipient->status->label() }}</x-badge>
                            @if ($recipient->error)
                                <p class="mt-0.5 max-w-[320px] truncate text-[11px] text-err-text" title="{{ $recipient->error }}">{{ $recipient->error }}</p>
                            @endif
                        </x-td>
                        {{-- Lecture : sans message déposé, la question ne se
                             pose pas — un tiret, jamais « non lu ». --}}
                        <x-td nowrap>
                            @if ($recipient->message === null)
                                <span class="text-[13px] text-muted">—</span>
                            @elseif ($recipient->message->read_at)
                                <x-badge tone="ok">{{ __('backoffice.campaigns.read_badge') }}</x-badge>
                                <span class="ml-1.5 text-xs text-muted tabular-nums">{{ $recipient->message->read_at->format('d/m/Y H:i') }}</span>
                            @else
                                <x-badge>{{ __('backoffice.campaigns.unread_badge') }}</x-badge>
                            @endif
                        </x-td>
                        <x-td align="right" nowrap>
                            @if ($canSend && $recipient->status->isReplayable())
                                <x-button size="sm" variant="secondary"
                                          wire:click="confirmReplay('{{ $recipient->id }}')" target="confirmReplay">
                                    {{ __('backoffice.campaigns.replay') }}
                                </x-button>
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

    {{-- ---------------------------------------------------------------- --}}
    {{-- Rejeu : unitaire ou en masse, une confirmation partagée.          --}}
    {{-- Hors du bloc « brouillon » : un rejeu ne concerne qu'un envoi     --}}
    {{-- déjà parti, soit exactement le cas contraire.                     --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($confirmingReplayId !== null)
        <x-confirm close="cancelReplay" action="replay"
                   :title="__('backoffice.campaigns.confirm_replay_title')"
                   :body="__('backoffice.campaigns.confirm_replay_body')"
                   :confirm-label="__('backoffice.campaigns.replay')"
                   :loading="__('backoffice.common.sending')" />
    @endif

    @if ($confirmingReplayAll)
        <x-confirm close="cancelReplay" action="replayAllFailures"
                   :title="__('backoffice.campaigns.confirm_replay_all_title')"
                   :body="trans_choice('backoffice.campaigns.confirm_replay_all_body', $failed, ['count' => $failed])"
                   :confirm-label="__('backoffice.campaigns.replay_all')"
                   :loading="__('backoffice.common.sending')" />
    @endif
</div>
