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
            </x-slot:head>

            @foreach ($campaigns as $campaign)
                {{-- `wire:key` obligatoire : la liste se réordonne dès qu'une
                     campagne part, et le diff DOM recyclerait les lignes. --}}
                <tr wire:key="campaign-{{ $campaign->id }}" class="group relative transition-colors hover:bg-surface">
                    <x-td>
                        {{-- Toute la ligne mène au détail, mais la navigation
                             reste portée par un vrai lien — celui du titre,
                             doublé d'une surface étirée en absolu. Un
                             `onclick` sur le `<tr>` serait inatteignable au
                             clavier et court-circuiterait `wire:navigate`.
                             Les actions ont quitté la ligne pour la page de
                             détail : plus rien ici n'entre en concurrence
                             avec ce lien. --}}
                        <a href="{{ route('bo.campaigns.show', $campaign) }}" wire:navigate
                           class="block truncate text-[13px] font-bold text-ink after:absolute after:inset-0 after:content-[''] group-hover:text-primary-text">
                            {{ $campaign->title }}
                        </a>
                        <span class="mt-0.5 block max-w-[520px] truncate text-xs text-muted">{{ $campaign->body }}</span>
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
        {{-- Deux colonnes : on rédige à gauche, on voit à droite. L'aperçu
             était jusqu'ici coincé entre deux champs, donc invisible dès qu'on
             faisait défiler — or c'est la seule chose qui dise à l'agent ce que
             cinq mille conducteurs vont réellement lire. Il est maintenant
             collant, et ne quitte plus l'écran. --}}
        <x-modal close="cancelCompose" align="start" size="xl"
                 :title="$editingId === null ? __('backoffice.campaigns.compose_title') : __('backoffice.campaigns.compose_edit_title')"
                 :description="__('backoffice.campaigns.compose_hint')">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">

                {{-- ---------------------------------------------------- --}}
                {{-- Colonne de rédaction, en trois temps numérotés.       --}}
                {{-- ---------------------------------------------------- --}}
                <div class="min-w-0 space-y-7">

                    {{-- 1. Le message --}}
                    <section class="space-y-3.5">
                        <x-step-heading :step="1" :title="__('backoffice.campaigns.step_message')"
                                        :hint="__('backoffice.campaigns.step_message_hint')" />

                        <x-field :label="__('backoffice.campaigns.field_title')" name="title" id="campaign-title"
                                 wire:model.live.debounce.400ms="title"
                                 :placeholder="__('backoffice.campaigns.title_placeholder')" required />

                        <div>
                            <x-field :label="__('backoffice.campaigns.field_body')" name="body" id="campaign-body" type="textarea" rows="6"
                                     wire:model.live.debounce.400ms="body"
                                     :placeholder="__('backoffice.campaigns.body_placeholder')" required />
                            {{-- Le compteur ne se colore qu'en approchant de la
                                 limite : rouge en permanence, il ne voudrait
                                 plus rien dire une fois dépassé. --}}
                            @php($length = mb_strlen($body))
                            <p @class([
                                'mt-1 text-right text-[11px] font-medium tabular-nums',
                                'text-err-text' => $length > 2000,
                                'text-warn-text' => $length > 1800 && $length <= 2000,
                                'text-muted' => $length <= 1800,
                            ])>
                                {{ number_format($length, 0, ',', ' ') }} / 2 000
                            </p>
                        </div>
                    </section>

                    {{-- 2. L'image --}}
                    <section class="space-y-3.5">
                        <x-step-heading :step="2" :title="__('backoffice.campaigns.step_image')"
                                        :hint="__('backoffice.campaigns.step_image_hint')" optional />

                        {{-- Zone de dépôt : le champ natif est masqué mais
                             reste le contrôle réel, donc le clavier et les
                             lecteurs d'écran continuent de l'atteindre. --}}
                        <div x-data="{ over: false }"
                             x-on:dragover.prevent="over = true"
                             x-on:dragleave.prevent="over = false"
                             x-on:drop="over = false"
                             class="relative">
                            <label for="campaign-image"
                                   x-bind:class="over ? 'border-primary bg-primary-tint' : 'border-line bg-surface hover:border-primary hover:bg-primary-tint/40'"
                                   class="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-dashed px-4 py-7 text-center transition-colors">
                                <svg class="size-6 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>
                                </svg>
                                <span class="text-[13px] font-semibold text-ink">{{ __('backoffice.campaigns.image_drop') }}</span>
                                <span id="campaign-image-hint" class="text-[11px] text-muted">{{ __('backoffice.campaigns.image_hint') }}</span>
                            </label>
                            {{-- Masqué mais bien le contrôle réel : le clavier
                                 et les lecteurs d'écran l'atteignent par le
                                 `<label for>`. `aria-label` en plus, car le
                                 libellé visible décrit le geste (« glissez »)
                                 et non le champ. --}}
                            <input type="file" id="campaign-image" wire:model="image"
                                   accept="image/jpeg,image/png,image/webp"
                                   aria-label="{{ __('backoffice.campaigns.field_image') }}"
                                   aria-describedby="campaign-image-hint"
                                   class="sr-only" />
                        </div>

                        <div wire:loading wire:target="image" class="flex items-center gap-2 text-xs text-muted">
                            <svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                <path class="opacity-90" d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            {{ __('backoffice.campaigns.image_uploading') }}
                        </div>

                        @error('image') <p class="text-sm text-err-text">{{ $message }}</p> @enderror

                        @if ($image !== null && ! $errors->has('image'))
                            <div class="flex items-center gap-3 rounded-lg border border-line bg-card p-2 pr-3">
                                <img src="{{ $image->temporaryUrl() }}" alt="" class="size-12 shrink-0 rounded object-cover">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] font-semibold text-ink">{{ $image->getClientOriginalName() }}</span>
                                    <span class="block text-[11px] text-muted">{{ number_format($image->getSize() / 1024, 0, ',', ' ') }} Ko</span>
                                </span>
                                <button type="button" wire:click="removeImage"
                                        class="shrink-0 rounded p-1.5 text-muted transition-colors hover:bg-err-bg hover:text-err-text"
                                        aria-label="{{ __('backoffice.campaigns.image_remove') }}">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </div>
                        @endif

                        <x-field :label="__('backoffice.campaigns.field_deeplink')" name="deeplink" id="campaign-deeplink"
                                 wire:model="deeplink" placeholder="wigo://recharge" :hint="__('backoffice.campaigns.deeplink_hint')" />
                    </section>

                    {{-- 3. La cible --}}
                    <section class="space-y-3.5">
                        <x-step-heading :step="3" :title="__('backoffice.campaigns.step_audience')"
                                        :hint="__('backoffice.campaigns.step_audience_hint')" />

                        {{-- Des cartes plutôt que des puces : trois cibles aux
                             conséquences très différentes, dont une qui touche
                             tout le parc. Le choix mérite d'occuper la place
                             qu'il pèse. --}}
                        <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('backoffice.campaigns.audience_label') }}">
                            @foreach (\App\Enums\CampaignAudience::cases() as $case)
                                @php($picked = $audience === $case->value)
                                <button type="button" wire:key="audience-{{ $case->value }}"
                                        wire:click="$set('audience', '{{ $case->value }}')"
                                        role="radio" aria-checked="{{ $picked ? 'true' : 'false' }}"
                                        @class([
                                            'rounded-lg border p-3 text-left transition-colors',
                                            'border-primary bg-primary-tint ring-1 ring-primary' => $picked,
                                            'border-line bg-card hover:border-primary hover:bg-surface' => ! $picked,
                                        ])>
                                    <span @class(['block text-[13px] font-bold', 'text-primary-text' => $picked, 'text-ink' => ! $picked])>
                                        {{ $case->label() }}
                                    </span>
                                    <span class="mt-0.5 block text-[11px] leading-snug text-muted">{{ $case->hint() }}</span>
                                </button>
                            @endforeach
                        </div>

                        @if ($audience === \App\Enums\CampaignAudience::Segment->value)
                            <div class="space-y-4 rounded-lg border border-line bg-surface p-4">
                                <div>
                                    <p class="mb-2 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_status_label') }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($statuses as $case)
                                            <x-chip-filter wire:key="segment-status-{{ $case->value }}" wire:click="toggleStatus('{{ $case->value }}')" :active="in_array($case->value, $segmentStatuses, true)">
                                                {{ $case->label() }}
                                            </x-chip-filter>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="mb-2 text-xs font-semibold text-muted">{{ __('backoffice.campaigns.segment_vehicle_label') }}</p>
                                    {{-- Trois états : indifférent (null) est la
                                         valeur par défaut et ne doit pas se
                                         confondre avec « sans véhicule », qui
                                         restreint réellement l'audience. --}}
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-chip-filter wire:click="$set('segmentHasVehicle', null)" :active="$segmentHasVehicle === null">{{ __('backoffice.campaigns.vehicle_any') }}</x-chip-filter>
                                        <x-chip-filter wire:click="$set('segmentHasVehicle', true)" :active="$segmentHasVehicle === true">{{ __('backoffice.campaigns.vehicle_with') }}</x-chip-filter>
                                        <x-chip-filter wire:click="$set('segmentHasVehicle', false)" :active="$segmentHasVehicle === false">{{ __('backoffice.campaigns.vehicle_without') }}</x-chip-filter>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($audience === \App\Enums\CampaignAudience::Individual->value)
                            <div class="rounded-lg border border-line bg-surface p-4">
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
                                                        'flex w-full items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition-colors',
                                                        'border-primary bg-primary-tint' => $isPicked,
                                                        'border-line bg-card hover:border-primary' => ! $isPicked,
                                                    ])>
                                                <x-avatar :initials="$driver->initials()" size="sm" />
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-[13px] font-semibold text-ink">{{ $driver->fullName() }}</span>
                                                    <span class="block font-mono text-[11px] text-muted">{{ $driver->phone }}</span>
                                                </span>
                                                @if ($isPicked)
                                                    <svg class="size-4 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
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

                                <p class="mt-2.5 text-xs font-semibold text-muted">
                                    {{ trans_choice('backoffice.campaigns.selected_drivers', count($driverIds), ['count' => count($driverIds)]) }}
                                </p>
                                @error('driverIds') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </section>
                </div>

                {{-- ---------------------------------------------------- --}}
                {{-- Colonne d'aperçu, collante.                          --}}
                {{-- ---------------------------------------------------- --}}
                <aside class="lg:sticky lg:top-0 lg:self-start">
                    <p class="mb-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted">{{ __('backoffice.campaigns.preview') }}</p>

                    {{-- Un cadre de téléphone : le message n'arrive pas dans un
                         navigateur mais dans un fil de discussion, et le voir
                         à sa vraie largeur évite de découvrir une coquille ou
                         un pavé illisible sur cinq mille écrans. --}}
                    <div class="rounded-[22px] border-[6px] border-ink bg-ink p-0 shadow-lg">
                        <div class="rounded-[16px] bg-surface">
                            <div class="flex items-center justify-between rounded-t-[16px] border-b border-line bg-card px-3 py-2">
                                <span class="text-[11px] font-bold text-ink">{{ __('backoffice.campaigns.preview_thread') }}</span>
                                <span class="text-[10px] text-muted">{{ now()->format('H:i') }}</span>
                            </div>

                            <div class="min-h-[240px] space-y-2 px-3 py-3">
                                @if (trim($title) === '' && trim($body) === '' && $image === null)
                                    <p class="pt-16 text-center text-[11px] leading-relaxed text-muted">
                                        {{ __('backoffice.campaigns.preview_empty') }}
                                    </p>
                                @else
                                    {{-- Bulle système, comme dans le fil réel :
                                         pas d'émetteur, alignée à gauche. --}}
                                    <div class="max-w-[92%] rounded-xl rounded-tl-sm bg-card p-2.5 shadow-sm ring-1 ring-line">
                                        @if (trim($title) !== '')
                                            <p class="text-[12px] font-bold leading-snug text-ink">{{ $title }}</p>
                                        @endif
                                        @if ($image !== null && ! $errors->has('image'))
                                            <img src="{{ $image->temporaryUrl() }}" alt=""
                                                 class="mt-1.5 max-h-40 w-full rounded-lg object-cover">
                                        @endif
                                        @if (trim($body) !== '')
                                            <p class="mt-1 whitespace-pre-line text-[11.5px] leading-relaxed text-ink">{{ $body }}</p>
                                        @endif
                                        <p class="mt-1.5 text-right text-[9.5px] text-muted">{{ now()->format('H:i') }}</p>
                                    </div>

                                    @if ($deeplink !== '')
                                        <div class="max-w-[92%] rounded-lg border border-primary bg-primary-tint px-2.5 py-1.5">
                                            <span class="text-[10.5px] font-semibold text-primary-text">{{ __('backoffice.campaigns.preview_deeplink') }}</span>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Le nombre de destinataires est la dernière chose que
                         l'agent lit avant d'envoyer : il doit être impossible
                         à manquer, et il sort du même résolveur que l'envoi. --}}
                    <div class="mt-3 rounded-lg border border-primary bg-primary-tint px-4 py-3">
                        <p class="text-2xl font-semibold tabular-nums text-primary-text">
                            {{ trans_choice('backoffice.campaigns.recipient_count', $recipientCount, ['count' => number_format($recipientCount, 0, ',', ' ')]) }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.campaigns.recipient_count_hint') }}</p>
                    </div>
                </aside>
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
