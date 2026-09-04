{{--
    Campagnes sortantes.

    L'écran tient sur une table d'historique et un composeur. Le nombre de
    destinataires y est affiché en grand et recalculé à chaque changement de
    cible : c'est le seul garde-fou avant une notification qui part à des
    milliers de conducteurs, et il vient du même résolveur que l'envoi.

    Le bouton de composition vit dans l'en-tête du layout et parle à la
    racine par évènement Alpine.
--}}
<div x-on:open-campaign-composer.window="$wire.compose()">
    <x-slot:actions>
        <x-button x-on:click="$dispatch('open-campaign-composer')">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.campaigns.new') }}
        </x-button>
    </x-slot:actions>

    {{-- Bandeau : ce qui est parti, ce qui attend, et si c'est lu. --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.campaigns.kpi_sent')" :value="number_format($totals['sent'], 0, ',', ' ')" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.campaigns.kpi_drafts')" :value="number_format($totals['drafts'], 0, ',', ' ')" :tone="$totals['drafts'] > 0 ? 'warn' : 'neutral'">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.campaigns.kpi_delivered')" :value="number_format($totals['delivered'], 0, ',', ' ')" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.campaigns.kpi_read_rate')" :value="$totals['read_rate'] === null ? '—' : number_format($totals['read_rate'], 1, ',', ' ')" :unit="$totals['read_rate'] === null ? null : '%'">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByStatus(null)" :active="$status === null">{{ __('backoffice.campaigns.filter_all') }}</x-chip-filter>
            @foreach ($campaignStatuses as $case)
                <x-chip-filter wire:key="status-{{ $case->value }}" wire:click="filterByStatus('{{ $case->value }}')" :active="$status === $case->value">
                    {{ $case->label() }}
                </x-chip-filter>
            @endforeach
        </div>
    </x-toolbar>

    <x-panel class="mt-4" flush>
        <x-table loading="filterByStatus,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.campaigns.column_campaign') }}</x-th>
                <x-th>{{ __('backoffice.campaigns.column_audience') }}</x-th>
                <x-th>{{ __('backoffice.campaigns.column_status') }}</x-th>
                <x-th align="right">{{ __('backoffice.campaigns.column_recipients') }}</x-th>
                <x-th align="right">{{ __('backoffice.campaigns.column_read') }}</x-th>
                <x-th>{{ __('backoffice.campaigns.column_author') }}</x-th>
                <x-th>{{ __('backoffice.campaigns.column_date') }}</x-th>
                <x-th><span class="sr-only">{{ __('backoffice.campaigns.send') }}</span></x-th>
            </x-slot:head>

            @foreach ($campaigns as $campaign)
                {{-- `wire:key` obligatoire : la liste se réordonne dès qu'une
                     campagne part, et le diff DOM recyclerait les lignes. --}}
                <tr wire:key="campaign-{{ $campaign->id }}" class="transition-colors hover:bg-surface">
                    <x-td>
                        {{-- Le titre porte le lien plutôt que la ligne
                             entière : une ligne cliquable avalerait le
                             bouton « Envoyer », et ne se rejoint pas au
                             clavier. --}}
                        <a href="{{ route('bo.campaigns.show', $campaign) }}" wire:navigate
                           class="block max-w-[280px] truncate text-[13px] font-bold text-ink hover:text-primary">
                            {{ $campaign->title }}
                        </a>
                        <span class="mt-0.5 block max-w-[280px] truncate text-xs text-muted">{{ $campaign->body }}</span>
                    </x-td>
                    <x-td><x-badge :classes="$campaign->audience->badgeClasses()">{{ $campaign->audience->label() }}</x-badge></x-td>
                    <x-td><x-badge :classes="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-badge></x-td>
                    <x-td align="right" nowrap class="tabular-nums">
                        {{-- Un brouillon n'a rien déposé : afficher 0 se
                             lirait comme un envoi qui n'a touché personne.
                             Le tiret dit « pas encore ». --}}
                        @if ($campaign->status === \App\Enums\CampaignStatus::Draft)
                            <span class="text-[13px] text-muted">—</span>
                        @else
                            <span class="text-[13px] font-semibold text-ink">{{ number_format($campaign->delivered_count, 0, ',', ' ') }}</span>
                        @endif
                    </x-td>
                    <x-td align="right" nowrap class="tabular-nums">
                        {{-- Rien de déposé, pas de dénominateur : un tiret
                             plutôt qu'un 0 % qui se lirait comme un échec de
                             lecture. --}}
                        @if ($campaign->delivered_count > 0)
                            <span class="text-[13px] font-semibold text-ink">{{ number_format($campaign->read_count, 0, ',', ' ') }}</span>
                            <span class="ml-1 text-[11px] text-muted">{{ number_format($campaign->read_count / $campaign->delivered_count * 100, 1, ',', ' ') }} %</span>
                        @else
                            <span class="text-[13px] text-muted">—</span>
                        @endif
                    </x-td>
                    <x-td class="text-[13px]">{{ $campaign->createdByUser?->fullName() ?? __('backoffice.campaigns.unknown_author') }}</x-td>
                    <x-td muted nowrap class="text-xs">{{ ($campaign->sent_at ?? $campaign->created_at)?->diffForHumans() }}</x-td>
                    <x-td align="right" nowrap>
                        @if ($campaign->status === \App\Enums\CampaignStatus::Draft)
                            <x-button size="sm" wire:click="confirmSend('{{ $campaign->id }}')" target="confirmSend">{{ __('backoffice.campaigns.send') }}</x-button>
                        @endif
                    </x-td>
                </tr>
            @endforeach

            @if ($campaigns->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="primary" :title="__('backoffice.campaigns.none')" :hint="__('backoffice.campaigns.none_hint')">
                        <x-slot:action>
                            <x-button wire:click="compose" target="compose">{{ __('backoffice.campaigns.new') }}</x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif

            @if ($campaigns->hasPages())
                <x-slot:footer>{{ $campaigns->links() }}</x-slot:footer>
            @endif
        </x-table>
    </x-panel>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Composeur.                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($composerOpen)
        <x-modal close="cancelCompose" align="start" size="lg" :title="__('backoffice.campaigns.compose_title')">
            <div class="space-y-4">
                <x-field :label="__('backoffice.campaigns.field_title')" name="title" id="campaign-title"
                         wire:model.live.debounce.400ms="title" required />

                {{-- Le compteur et l'aperçu suivent la frappe : `.live` avec un
                     délai, pour ne pas faire un aller-retour par caractère. --}}
                <div>
                    <x-field :label="__('backoffice.campaigns.field_body')" name="body" id="campaign-body" type="textarea" rows="4"
                             wire:model.live.debounce.400ms="body" required />
                    <p @class(['mt-1 text-right text-[11px] tabular-nums', 'text-err-text' => mb_strlen($body) > 2000, 'text-muted' => mb_strlen($body) <= 2000])>
                        {{ mb_strlen($body) }} / 2 000
                    </p>

                    {{-- Aperçu : le message arrive dans le fil du conducteur
                         comme un message système. Le voir avant l'envoi évite
                         de découvrir une coquille sur cinq mille écrans. --}}
                    @if (trim($body) !== '' || $image !== null)
                        <div class="mt-2 rounded border border-line bg-surface p-3">
                            <p class="text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.campaigns.preview') }}</p>
                            <div class="mt-2 rounded bg-card px-3 py-2.5 shadow-sm">
                                @if (trim($title) !== '')
                                    <p class="text-[13px] font-bold text-ink">{{ $title }}</p>
                                @endif
                                {{-- L'image se lit au-dessus du texte, comme
                                     dans le fil : l'aperçu ne vaut que s'il
                                     montre le même ordre. --}}
                                @if ($image !== null && ! $errors->has('image'))
                                    <img src="{{ $image->temporaryUrl() }}" alt=""
                                         class="mt-1.5 max-h-48 w-full rounded object-cover">
                                @endif
                                @if (trim($body) !== '')
                                    <p class="mt-0.5 whitespace-pre-line text-[13px] leading-relaxed text-ink">{{ $body }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Images seulement : la chaîne ne porte aucun antivirus, et
                     un agent n'a pas à ouvrir un fichier quelconque. --}}
                <div>
                    <x-field :label="__('backoffice.campaigns.field_image')" name="image" id="campaign-image" type="file"
                             wire:model="image" accept="image/jpeg,image/png,image/webp"
                             :hint="__('backoffice.campaigns.image_hint')" />

                    <div wire:loading wire:target="image" class="mt-1 text-xs text-muted">
                        {{ __('backoffice.campaigns.image_uploading') }}
                    </div>

                    @if ($image !== null && ! $errors->has('image'))
                        <button type="button" wire:click="removeImage"
                                class="mt-1.5 text-xs font-semibold text-err-text hover:underline">
                            {{ __('backoffice.campaigns.image_remove') }}
                        </button>
                    @endif
                </div>

                <x-field :label="__('backoffice.campaigns.field_deeplink')" name="deeplink" id="campaign-deeplink"
                         wire:model="deeplink" placeholder="wigo://recharge" :hint="__('backoffice.campaigns.deeplink_hint')" />

                <div>
                    <p class="mb-1.5 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.audience_label') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (\App\Enums\CampaignAudience::cases() as $case)
                            <x-chip-filter wire:key="audience-{{ $case->value }}" wire:click="$set('audience', '{{ $case->value }}')" :active="$audience === $case->value">
                                {{ $case->label() }}
                            </x-chip-filter>
                        @endforeach
                    </div>
                </div>

                @if ($audience === \App\Enums\CampaignAudience::Segment->value)
                    <div class="rounded border border-line bg-surface p-4">
                        <p class="mb-1.5 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_status_label') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($statuses as $case)
                                <x-chip-filter wire:key="segment-status-{{ $case->value }}" wire:click="toggleStatus('{{ $case->value }}')" :active="in_array($case->value, $segmentStatuses, true)">
                                    {{ $case->label() }}
                                </x-chip-filter>
                            @endforeach
                        </div>

                        <p class="mb-1.5 mt-4 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_vehicle_label') }}</p>
                        {{-- Trois états : indifférent (null) est la valeur par
                             défaut et ne doit pas se confondre avec « sans
                             véhicule », qui restreint réellement l'audience. --}}
                        <div class="flex flex-wrap gap-1.5">
                            <x-chip-filter wire:click="$set('segmentHasVehicle', null)" :active="$segmentHasVehicle === null">{{ __('backoffice.campaigns.vehicle_any') }}</x-chip-filter>
                            <x-chip-filter wire:click="$set('segmentHasVehicle', true)" :active="$segmentHasVehicle === true">{{ __('backoffice.campaigns.vehicle_with') }}</x-chip-filter>
                            <x-chip-filter wire:click="$set('segmentHasVehicle', false)" :active="$segmentHasVehicle === false">{{ __('backoffice.campaigns.vehicle_without') }}</x-chip-filter>
                        </div>
                    </div>
                @endif

                @if ($audience === \App\Enums\CampaignAudience::Individual->value)
                    <div class="rounded border border-line bg-surface p-4">
                        <x-field :label="__('backoffice.campaigns.driver_search_label')" name="driverSearch" id="campaign-driver-search" type="search"
                                 wire:model.live.debounce.400ms="driverSearch"
                                 :placeholder="__('backoffice.campaigns.driver_search_placeholder')" />

                        <ul class="mt-2 space-y-1" aria-label="{{ __('backoffice.campaigns.driver_search_label') }}">
                            @forelse ($driverMatches as $driver)
                                @php($isPicked = in_array($driver->id, $driverIds, true))
                                <li wire:key="driver-{{ $driver->id }}">
                                    <button type="button" wire:click="toggleDriver('{{ $driver->id }}')"
                                            aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                            @class([
                                                'flex w-full items-center gap-2 rounded border px-3 py-2 text-left transition-colors',
                                                'border-primary bg-primary-tint' => $isPicked,
                                                'border-line bg-card hover:border-primary' => ! $isPicked,
                                            ])>
                                        <span class="text-[13px] font-semibold text-ink">{{ $driver->fullName() }}</span>
                                        <span class="font-mono text-[11px] text-muted">{{ $driver->phone }}</span>
                                        @if ($isPicked)
                                            <svg class="ml-auto size-4 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                        @endif
                                    </button>
                                </li>
                            @empty
                                <li class="px-1 py-2 text-xs text-muted">
                                    {{ $driverSearch === ''
                                        ? __('backoffice.campaigns.driver_search_hint')
                                        : __('backoffice.campaigns.driver_search_empty') }}
                                </li>
                            @endforelse
                        </ul>

                        <p class="mt-2 text-xs font-semibold text-muted">
                            {{ trans_choice('backoffice.campaigns.selected_drivers', count($driverIds), ['count' => count($driverIds)]) }}
                        </p>
                        @error('driverIds') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Le nombre de destinataires est la dernière chose que l'agent
                     lit avant d'envoyer : il doit être impossible à manquer. --}}
                <div class="rounded border border-primary bg-primary-tint px-4 py-3">
                    <p class="text-2xl font-semibold text-primary-text tabular-nums">
                        {{ trans_choice('backoffice.campaigns.recipient_count', $recipientCount, ['count' => number_format($recipientCount, 0, ',', ' ')]) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.recipient_count_hint') }}</p>
                </div>
            </div>

            <x-slot:footer>
                <x-button variant="secondary" wire:click="cancelCompose">{{ __('backoffice.campaigns.cancel') }}</x-button>
                <x-button variant="secondary" wire:click="saveDraft" target="saveDraft">
                    {{ __('backoffice.campaigns.save_draft') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
                <x-button wire:click="confirmSend" target="confirmSend">{{ __('backoffice.campaigns.send') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Confirmation d'envoi : le bouton est gardé, une notification à    --}}
    {{-- des milliers de conducteurs ne part pas deux fois.                --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($confirmingSendId !== null)
        <x-confirm close="cancelSend" action="send"
                   :title="__('backoffice.campaigns.confirm_send_title')"
                   :body="__('backoffice.campaigns.confirm_send_body')"
                   :confirm-label="__('backoffice.campaigns.send')"
                   :loading="__('backoffice.common.sending')">
            {{-- `confirmingCount` et non `recipientCount` : la confirmation
                 porte sur la campagne réellement envoyée, pas sur l'état du
                 composeur. --}}
            <p class="mt-2 text-xl font-semibold text-primary-text tabular-nums">
                {{ trans_choice('backoffice.campaigns.recipient_count', $confirmingCount ?? 0, ['count' => number_format($confirmingCount ?? 0, 0, ',', ' ')]) }}
            </p>
        </x-confirm>
    @endif
</div>
