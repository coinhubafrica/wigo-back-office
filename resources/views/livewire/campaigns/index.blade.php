{{--
    Campagnes sortantes.

    L'écran tient sur une table d'historique et un composeur. Le nombre de
    destinataires y est affiché en grand et recalculé à chaque changement de
    cible : c'est le seul garde-fou avant une notification qui part à des
    milliers de conducteurs, et il vient du même résolveur que l'envoi.
--}}
<div>
    {{-- Bandeau : ce qui est parti, ce qui attend, et si c'est lu. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.kpi_sent') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($totals['sent'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.kpi_drafts') }}</p>
            <p @class([
                'mt-1.5 text-2xl font-semibold',
                'text-warn-text' => $totals['drafts'] > 0,
                'text-ink' => $totals['drafts'] === 0,
            ])>{{ number_format($totals['drafts'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.kpi_delivered') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ number_format($totals['delivered'], 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.kpi_read_rate') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">
                {{ $totals['read_rate'] === null ? '—' : number_format($totals['read_rate'], 1, ',', ' ').' %' }}
            </p>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-1.5">
            {{-- `aria-pressed` : le filtre actif n'est signalé que par la
                 couleur, invisible pour un lecteur d'écran sans cet état. --}}
            <button wire:click="filterByStatus(null)"
                    aria-pressed="{{ $status === null ? 'true' : 'false' }}"
                    @class([
                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                        'border-primary bg-primary-tint text-primary-text' => $status === null,
                        'border-line bg-card text-muted hover:border-primary' => $status !== null,
                    ])>
                {{ __('backoffice.campaigns.filter_all') }}
            </button>
            @foreach ($campaignStatuses as $case)
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

        <button wire:click="compose" class="flex items-center gap-1.5 rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.campaigns.new') }}
        </button>
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-surface">
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_campaign') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_audience') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_status') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_recipients') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_read') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_author') }}</th>
                        <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.column_date') }}</th>
                        <th class="border-b border-line px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        {{-- `wire:key` obligatoire : la liste se réordonne dès qu'une
                             campagne part, et le diff DOM recyclerait les lignes. --}}
                        <tr wire:key="campaign-{{ $campaign->id }}" class="transition-colors hover:bg-surface">
                            <td class="border-b border-line px-4 py-3">
                                {{-- Le titre porte le lien plutôt que la ligne
                                     entière : une ligne cliquable avalerait le
                                     bouton « Envoyer », et ne se rejoint pas au
                                     clavier. --}}
                                <a href="{{ route('bo.campaigns.show', $campaign) }}" wire:navigate
                                   class="block max-w-[280px] truncate text-[13px] font-bold text-ink hover:text-primary">
                                    {{ $campaign->title }}
                                </a>
                                <div class="mt-0.5 max-w-[280px] truncate text-xs text-muted">{{ $campaign->body }}</div>
                            </td>
                            <td class="border-b border-line px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $campaign->audience->badgeClasses() }}">
                                    {{ $campaign->audience->label() }}
                                </span>
                            </td>
                            <td class="border-b border-line px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $campaign->status->badgeClasses() }}">
                                    {{ $campaign->status->label() }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-right">
                                {{-- Un brouillon n'a rien déposé : afficher 0 se
                                     lirait comme un envoi qui n'a touché personne.
                                     Le tiret dit « pas encore ». --}}
                                @if ($campaign->status === \App\Enums\CampaignStatus::Draft)
                                    <span class="text-[13px] text-muted">—</span>
                                @else
                                    <span class="text-[13px] font-semibold text-ink">{{ number_format($campaign->delivered_count, 0, ',', ' ') }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-right">
                                {{-- Rien de déposé, pas de dénominateur : un tiret
                                     plutôt qu'un 0 % qui se lirait comme un échec de
                                     lecture. --}}
                                @if ($campaign->delivered_count > 0)
                                    <span class="text-[13px] font-semibold text-ink">{{ number_format($campaign->read_count, 0, ',', ' ') }}</span>
                                    <span class="ml-1 text-[11px] text-muted">{{ number_format($campaign->read_count / $campaign->delivered_count * 100, 1, ',', ' ') }} %</span>
                                @else
                                    <span class="text-[13px] text-muted">—</span>
                                @endif
                            </td>
                            <td class="border-b border-line px-4 py-3 text-[13px] text-ink">
                                {{ $campaign->createdByUser?->fullName() ?? __('backoffice.campaigns.unknown_author') }}
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-xs text-muted">
                                {{ ($campaign->sent_at ?? $campaign->created_at)?->diffForHumans() }}
                            </td>
                            <td class="whitespace-nowrap border-b border-line px-4 py-3 text-right">
                                @if ($campaign->status === \App\Enums\CampaignStatus::Draft)
                                    <button wire:click="confirmSend('{{ $campaign->id }}')"
                                            class="rounded bg-primary px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-hover">
                                        {{ __('backoffice.campaigns.send') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center">
                                <p class="text-sm font-semibold text-ink">{{ __('backoffice.campaigns.none') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('backoffice.campaigns.none_hint') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $campaigns->links() }}
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Composeur.                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($composerOpen)
        <x-modal close="cancelCompose" align="start" max-width="max-w-2xl"
                 :title="__('backoffice.campaigns.compose_title')">
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label for="campaign-title" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.campaigns.field_title') }}</label>
                    <input wire:model.live.debounce.400ms="title" id="campaign-title" type="text"
                           class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('title') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                {{-- Le compteur et l'aperçu suivent la frappe : `.live` avec un
                     délai, pour ne pas faire un aller-retour par caractère. --}}
                <div>
                    <div class="mb-1.5 flex items-baseline justify-between">
                        <label for="campaign-body" class="block text-xs font-semibold text-muted">{{ __('backoffice.campaigns.field_body') }}</label>
                        <span @class([
                            'text-[11px] tabular-nums',
                            'text-err-text' => mb_strlen($body) > 2000,
                            'text-muted' => mb_strlen($body) <= 2000,
                        ])>{{ mb_strlen($body) }} / 2 000</span>
                    </div>
                    <textarea wire:model.live.debounce.400ms="body" id="campaign-body" rows="4"
                              class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary"></textarea>
                    @error('body') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror

                    {{-- Aperçu : le message arrive dans le fil du conducteur
                         comme un message système. Le voir avant l'envoi évite
                         de découvrir une coquille sur cinq mille écrans. --}}
                    @if (trim($body) !== '')
                        <div class="mt-3 rounded border border-line bg-surface p-3">
                            <p class="text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.preview') }}</p>
                            <div class="mt-2 rounded bg-card px-3 py-2.5">
                                @if (trim($title) !== '')
                                    <p class="text-[13px] font-bold text-ink">{{ $title }}</p>
                                @endif
                                <p class="mt-0.5 whitespace-pre-line text-[13px] leading-relaxed text-ink">{{ $body }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div>
                    <label for="campaign-deeplink" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.campaigns.field_deeplink') }}</label>
                    <input wire:model="deeplink" id="campaign-deeplink" type="text" placeholder="wigo://recharge"
                           class="block w-full rounded border border-input px-3 py-2 font-mono text-sm placeholder:text-muted focus:border-primary">
                    <p class="mt-1 text-xs text-muted">{{ __('backoffice.campaigns.deeplink_hint') }}</p>
                    @error('deeplink') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <p class="mb-1.5 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.audience_label') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        {{-- `aria-pressed` : la cible retenue n'est signalée que par la
                             couleur, invisible pour un lecteur d'écran sans cet état. --}}
                        @foreach (\App\Enums\CampaignAudience::cases() as $case)
                            <button type="button" wire:key="audience-{{ $case->value }}"
                                    wire:click="$set('audience', '{{ $case->value }}')"
                                    aria-pressed="{{ $audience === $case->value ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                        'border-primary bg-primary-tint text-primary-text' => $audience === $case->value,
                                        'border-line bg-card text-muted hover:border-primary' => $audience !== $case->value,
                                    ])>
                                {{ $case->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($audience === \App\Enums\CampaignAudience::Segment->value)
                    <div class="rounded border border-line bg-surface p-4">
                        <p class="mb-1.5 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_status_label') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($statuses as $case)
                                @php($isOn = in_array($case->value, $segmentStatuses, true))
                                <button type="button" wire:key="segment-status-{{ $case->value }}"
                                        wire:click="toggleStatus('{{ $case->value }}')"
                                        aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                                        @class([
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                            'border-primary bg-primary-tint text-primary-text' => $isOn,
                                            'border-line bg-card text-muted hover:border-primary' => ! $isOn,
                                        ])>
                                    {{ $case->label() }}
                                </button>
                            @endforeach
                        </div>

                        <p class="mb-1.5 mt-4 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_vehicle_label') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            {{-- Trois états : indifférent (null) est la valeur par
                                 défaut et ne doit pas se confondre avec « sans
                                 véhicule », qui restreint réellement l'audience. --}}
                            <button type="button" wire:click="$set('segmentHasVehicle', null)"
                                    aria-pressed="{{ $segmentHasVehicle === null ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                        'border-primary bg-primary-tint text-primary-text' => $segmentHasVehicle === null,
                                        'border-line bg-card text-muted hover:border-primary' => $segmentHasVehicle !== null,
                                    ])>
                                {{ __('backoffice.campaigns.vehicle_any') }}
                            </button>
                            <button type="button" wire:click="$set('segmentHasVehicle', true)"
                                    aria-pressed="{{ $segmentHasVehicle === true ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                        'border-primary bg-primary-tint text-primary-text' => $segmentHasVehicle === true,
                                        'border-line bg-card text-muted hover:border-primary' => $segmentHasVehicle !== true,
                                    ])>
                                {{ __('backoffice.campaigns.vehicle_with') }}
                            </button>
                            <button type="button" wire:click="$set('segmentHasVehicle', false)"
                                    aria-pressed="{{ $segmentHasVehicle === false ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
                                        'border-primary bg-primary-tint text-primary-text' => $segmentHasVehicle === false,
                                        'border-line bg-card text-muted hover:border-primary' => $segmentHasVehicle !== false,
                                    ])>
                                {{ __('backoffice.campaigns.vehicle_without') }}
                            </button>
                        </div>
                    </div>
                @endif

                @if ($audience === \App\Enums\CampaignAudience::Individual->value)
                    <div class="rounded border border-line bg-surface p-4">
                        <label for="campaign-driver-search" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.campaigns.driver_search_label') }}</label>
                        <input wire:model.live.debounce.400ms="driverSearch" id="campaign-driver-search" type="search"
                               placeholder="{{ __('backoffice.campaigns.driver_search_placeholder') }}"
                               class="block w-full rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">

                        <div class="mt-2 space-y-1">
                            @forelse ($driverMatches as $driver)
                                @php($isPicked = in_array($driver->id, $driverIds, true))
                                <button type="button" wire:key="driver-{{ $driver->id }}"
                                        wire:click="toggleDriver('{{ $driver->id }}')"
                                        aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                        @class([
                                            'flex w-full items-center gap-2 rounded border px-3 py-2 text-left transition-colors',
                                            'border-primary bg-primary-tint' => $isPicked,
                                            'border-line bg-card hover:border-primary' => ! $isPicked,
                                        ])>
                                    <span class="text-[13px] font-semibold text-ink">{{ $driver->fullName() }}</span>
                                    <span class="font-mono text-[11px] text-muted">{{ $driver->phone }}</span>
                                    <span class="flex-1"></span>
                                    @if ($isPicked)
                                        <svg class="size-4 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    @endif
                                </button>
                            @empty
                                <p class="px-1 py-2 text-xs text-muted">
                                    {{ $driverSearch === ''
                                        ? __('backoffice.campaigns.driver_search_hint')
                                        : __('backoffice.campaigns.driver_search_empty') }}
                                </p>
                            @endforelse
                        </div>

                        <p class="mt-2 text-xs font-semibold text-muted">
                            {{ trans_choice('backoffice.campaigns.selected_drivers', count($driverIds), ['count' => count($driverIds)]) }}
                        </p>
                        @error('driverIds') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Le nombre de destinataires est la dernière chose que l'agent
                     lit avant d'envoyer : il doit être impossible à manquer. --}}
                <div class="rounded border border-primary bg-primary-tint px-4 py-3">
                    <p class="text-2xl font-semibold text-primary-text">
                        {{ trans_choice('backoffice.campaigns.recipient_count', $recipientCount, ['count' => number_format($recipientCount, 0, ',', ' ')]) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.recipient_count_hint') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-4">
                <button wire:click="cancelCompose" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.campaigns.cancel') }}
                </button>
                <button wire:click="saveDraft" class="rounded border border-line bg-surface px-3.5 py-2 text-sm font-semibold text-ink hover:bg-line">
                    {{ __('backoffice.campaigns.save_draft') }}
                </button>
                <button wire:click="confirmSend" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.campaigns.send') }}
                </button>
            </div>
        </x-modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Confirmation d'envoi.                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($confirmingSendId !== null)
        <x-modal close="cancelSend" max-width="max-w-md"
                 :label="__('backoffice.campaigns.confirm_send_title')">
            <div class="px-5 pb-4 pt-5">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.campaigns.confirm_send_title') }}</p>
                {{-- `confirmingCount` et non `recipientCount` : la
                     confirmation porte sur la campagne réellement envoyée,
                     pas sur l'état du composeur. --}}
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
</div>
