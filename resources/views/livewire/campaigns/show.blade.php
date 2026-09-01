{{--
    Détail d'une campagne.

    L'écran est en lecture seule : ce qui a été écrit, à qui, et qui l'a lu.
    Le message vient en premier et en entier — c'est la seule trace de ce que
    les conducteurs ont réellement reçu, et le tronquer ferait perdre le sens
    du reste de la page. Seul un brouillon porte encore une action : l'envoi.
--}}
<div class="flex max-w-[860px] flex-col gap-4">
    <a href="{{ route('bo.campaigns') }}" wire:navigate
       class="flex w-fit items-center gap-1.5 rounded border border-line bg-card px-3 py-2 text-sm font-semibold text-ink hover:bg-surface">
        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        {{ __('backoffice.campaigns.back_to_list') }}
    </a>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Le message, tel qu'il est lu côté conducteur.                     --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="rounded border border-line bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">
                {{ __('backoffice.campaigns.message_section') }}
            </h2>
            <span class="flex-1"></span>
            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $campaign->status->badgeClasses() }}">
                {{ $campaign->status->label() }}
            </span>
        </div>
        <div class="px-5 py-4">
            <h1 class="text-lg font-semibold text-ink">{{ $campaign->title }}</h1>
            <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.message_hint') }}</p>

            {{-- `whitespace-pre-line` : les retours à la ligne saisis dans le
                 composeur font partie du message et doivent survivre ici. --}}
            <div class="mt-3 rounded-lg rounded-tl-sm border border-line bg-surface px-4 py-3.5 text-sm leading-relaxed text-ink whitespace-pre-line">{{ $campaign->body }}</div>

            @if ($campaign->deeplink)
                <p class="mt-2.5 flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted">{{ __('backoffice.campaigns.deeplink_label') }}</span>
                    <span class="rounded border border-line bg-surface px-2 py-1 font-mono text-[11px] text-ink">{{ $campaign->deeplink }}</span>
                </p>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Statistiques.                                                     --}}
    {{-- ---------------------------------------------------------------- --}}
    @php($isDraft = $campaign->status === \App\Enums\CampaignStatus::Draft)

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">
                {{ $isDraft
                    ? __('backoffice.campaigns.stat_recipients_estimate')
                    : __('backoffice.campaigns.stat_recipients') }}
            </p>
            <p class="mt-2 text-2xl font-semibold text-ink">
                {{ number_format($isDraft ? ($pending ?? 0) : $delivered, 0, ',', ' ') }}
            </p>
            @if ($isDraft)
                <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.stat_recipients_estimate_hint') }}</p>
            @endif
        </div>

        {{-- Rien n'est parti : un 0 lecture se lirait comme un échec, alors
             que la question ne se pose pas encore. --}}
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.stat_read') }}</p>
            @if ($isDraft)
                <p class="mt-2 text-2xl font-semibold text-muted">—</p>
                <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.not_applicable') }}</p>
            @else
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format($read, 0, ',', ' ') }}</p>
            @endif
        </div>

        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.stat_rate') }}</p>
            @if ($isDraft || $rate === null)
                <p class="mt-2 text-2xl font-semibold text-muted">—</p>
                @if ($isDraft)
                    <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.not_applicable') }}</p>
                @endif
            @else
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format($rate, 1, ',', ' ') }} %</p>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Détails.                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="rounded border border-line bg-card">
        <h2 class="border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-wide text-muted">
            {{ __('backoffice.campaigns.details_section') }}
        </h2>
        <div class="px-5">
            <div class="flex items-center justify-between gap-4 border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.campaigns.detail_status') }}</span>
                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $campaign->status->badgeClasses() }}">
                    {{ $campaign->status->label() }}
                </span>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.campaigns.detail_audience') }}</span>
                <span class="flex flex-wrap items-center justify-end gap-1.5">
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $campaign->audience->badgeClasses() }}">
                        {{ $campaign->audience->label() }}
                    </span>
                    @foreach ($segmentLabels as $label)
                        <span wire:key="segment-label-{{ $loop->index }}"
                              class="rounded-full border border-line bg-surface px-2.5 py-1 text-[11px] font-semibold text-muted">
                            {{ $label }}
                        </span>
                    @endforeach
                </span>
            </div>
            <div class="flex justify-between gap-4 border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.campaigns.detail_author') }}</span>
                <b class="text-ink">{{ $campaign->createdByUser?->fullName() ?? __('backoffice.campaigns.unknown_author') }}</b>
            </div>
            <div class="flex justify-between gap-4 border-b border-line py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.campaigns.detail_created_at') }}</span>
                <b class="text-ink">{{ $campaign->created_at?->format('d/m/Y H:i') ?? '—' }}</b>
            </div>
            <div class="flex justify-between gap-4 py-2.5 text-sm">
                <span class="text-muted">{{ __('backoffice.campaigns.detail_sent_at') }}</span>
                <b class="text-ink">{{ $campaign->sent_at?->format('d/m/Y H:i') ?? '—' }}</b>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Destinataires : les messages déposés font foi.                    --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="rounded border border-line bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">
                {{ __('backoffice.campaigns.recipients_section') }}
            </h2>
            @unless ($isDraft)
                <span class="flex-1"></span>
                {{-- `aria-pressed` : le filtre retenu n'est signalé que par la
                     couleur, invisible pour un lecteur d'écran sans cet état. --}}
                <div class="flex flex-wrap gap-1.5">
                    @foreach (['all' => __('backoffice.campaigns.filter_all'), 'read' => __('backoffice.campaigns.filter_read'), 'unread' => __('backoffice.campaigns.filter_unread')] as $value => $label)
                        <button type="button" wire:key="recipient-filter-{{ $value }}"
                                wire:click="filterBy('{{ $value }}')"
                                aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                                @class([
                                    'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                    'border-primary bg-primary-tint text-primary-text' => $filter === $value,
                                    'border-line bg-card text-muted hover:border-primary' => $filter !== $value,
                                ])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endunless
        </div>

        @if ($isDraft)
            <p class="px-5 py-8 text-center text-sm text-muted">{{ __('backoffice.campaigns.recipients_draft') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-surface">
                            <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_driver') }}</th>
                            <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_phone') }}</th>
                            <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_read_state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recipients as $message)
                            @php($driver = $message->conversation?->driver)
                            <tr wire:key="recipient-{{ $message->id }}">
                                <td class="border-b border-line px-4 py-3">
                                    @if ($driver)
                                        <a href="{{ route('bo.drivers.show', $driver) }}" wire:navigate
                                           class="text-[13px] font-semibold text-primary-text hover:underline">
                                            {{ $driver->fullName() }}
                                        </a>
                                    @else
                                        <span class="text-[13px] text-muted">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap border-b border-line px-4 py-3 font-mono text-xs text-muted">
                                    {{ $driver?->phone ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap border-b border-line px-4 py-3">
                                    @if ($message->read_at)
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold bg-ok-bg text-ok-text">
                                            {{ __('backoffice.campaigns.read_badge') }}
                                        </span>
                                        <span class="ml-1.5 text-xs text-muted">{{ $message->read_at->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="text-xs text-muted">{{ __('backoffice.campaigns.unread_badge') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-10 text-center">
                                    <p class="text-sm font-semibold text-ink">{{ __('backoffice.campaigns.recipients_none') }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ __('backoffice.campaigns.recipients_none_hint') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">
                {{ $recipients->links() }}
            </div>
        @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Envoi : un brouillon seulement.                                   --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($isDraft)
        <div class="rounded border border-line bg-card p-5">
            <p class="text-sm font-semibold text-ink">{{ __('backoffice.campaigns.send_section') }}</p>
            <p class="mt-1 text-xs text-muted">{{ __('backoffice.campaigns.send_section_hint') }}</p>
            <button wire:click="confirmSend"
                    class="mt-3 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                {{ __('backoffice.campaigns.send') }}
            </button>
        </div>

        @if ($confirmingSend)
            <x-modal close="cancelSend" max-width="max-w-md"
                     :label="__('backoffice.campaigns.confirm_send_title')">
                <div class="px-5 pb-4 pt-5">
                    <p class="text-sm font-semibold text-ink">{{ __('backoffice.campaigns.confirm_send_title') }}</p>
                    {{-- `confirmingCount` et non `pending` : le nombre est figé
                         à l'ouverture, pour ne pas bouger entre la lecture et
                         le clic. --}}
                    <p class="mt-2 text-xl font-semibold text-primary-text">
                        {{ trans_choice('backoffice.campaigns.recipient_count', $confirmingCount ?? 0, ['count' => number_format($confirmingCount ?? 0, 0, ',', ' ')]) }}
                    </p>
                    <p class="mt-1.5 text-sm text-muted">{{ __('backoffice.campaigns.confirm_send_body') }}</p>
                </div>
                <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                    <button wire:click="cancelSend" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.campaigns.cancel') }}
                    </button>
                    <button wire:click="send" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                        {{ __('backoffice.campaigns.send') }}
                    </button>
                </div>
            </x-modal>
        @endif
    @endif
</div>
