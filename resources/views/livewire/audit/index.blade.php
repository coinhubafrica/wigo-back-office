{{-- Le bouton d'export vit dans l'en-tête du layout, hors de la racine
     Livewire : c'est une ancre vers le contrôleur, jamais un `wire:*`
     (cf. .ai/rules/components.md). --}}
<div>
    <x-slot:actions>
        @if ($canExport)
            <a href="{{ $exportUrl }}"
               class="inline-flex items-center gap-2 rounded border border-line bg-white px-3 py-2 text-sm font-medium text-ink transition-colors hover:bg-surface">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>
                </svg>
                {{ __('backoffice.audit.export') }}
            </a>
        @endif
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-3">
        <x-kpi-card :label="__('backoffice.audit.kpi_actions')" :value="number_format($kpis['actions'], 0, ',', ' ')" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5"/><rect x="4" y="3" width="16" height="18" rx="2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.audit.kpi_agents')" :value="number_format($kpis['agents'], 0, ',', ' ')" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        {{-- Le nombre d'écritures sans agent dit que le webhook et les tâches
             planifiées tournent : à zéro, il est plus inquiétant qu'à mille. --}}
        <x-kpi-card :label="__('backoffice.audit.kpi_system')" :value="number_format($kpis['system'], 0, ',', ' ')"
                    :hint="__('backoffice.audit.system_agent_hint')" tone="neutral">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <x-toolbar class="mt-5">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($periods as $value => $label)
                <x-chip-filter wire:key="period-{{ $value }}" wire:click="filterByPeriod('{{ $value }}')" :active="$period === $value">
                    {{ $label }}
                </x-chip-filter>
            @endforeach
        </div>
        <x-slot:end>
            <x-field :label="__('backoffice.audit.agent')" name="agent" type="select" label-hidden
                     wire:model.live="agent" class="w-52">
                <option value="">{{ __('backoffice.audit.all_agents') }}</option>
                <option value="system">{{ __('backoffice.audit.system_agent') }}</option>
                @foreach ($agents as $item)
                    <option value="{{ $item->id }}">{{ $item->fullName() }}</option>
                @endforeach
            </x-field>
            <x-field :label="__('backoffice.audit.search')" name="search" type="search" label-hidden
                     wire:model.live.debounce.400ms="search"
                     :placeholder="__('backoffice.audit.search_placeholder')" class="w-72" />
        </x-slot:end>
    </x-toolbar>

    <x-toolbar class="mt-3">
        <div class="flex flex-wrap gap-1.5">
            <x-chip-filter wire:click="filterByModule(null)" :active="$module === null">
                {{ __('backoffice.audit.all_modules') }}
            </x-chip-filter>
            @foreach ($modules as $item)
                <x-chip-filter wire:key="module-{{ $item->value }}" wire:click="filterByModule('{{ $item->value }}')"
                               :active="$module === $item->value">
                    {{ $item->label() }}
                </x-chip-filter>
            @endforeach
        </div>
    </x-toolbar>

    {{-- Les actions précises n'apparaissent qu'une fois un module retenu :
         vingt-deux puces d'un bloc ne se lisent pas. --}}
    @if ($actions !== [])
        <x-toolbar class="mt-2">
            <div class="flex flex-wrap gap-1.5">
                <x-chip-filter wire:click="filterByAction(null)" :active="$action === null">
                    {{ __('backoffice.audit.all_actions') }}
                </x-chip-filter>
                @foreach ($actions as $item)
                    <x-chip-filter wire:key="action-{{ $item->value }}" wire:click="filterByAction('{{ $item->value }}')"
                                   :active="$action === $item->value">
                        {{ $item->label() }}
                    </x-chip-filter>
                @endforeach
            </div>
        </x-toolbar>
    @endif

    <x-panel class="mt-4" :title="__('backoffice.audit.journal_title')" :count="$rows->total()" flush>
        <x-table loading="filterByPeriod,filterByModule,filterByAction,resetFilters,search,agent,gotoPage,previousPage,nextPage">
            <x-slot:head>
                <x-th>{{ __('backoffice.audit.column_when') }}</x-th>
                <x-th>{{ __('backoffice.audit.column_action') }}</x-th>
                <x-th>{{ __('backoffice.audit.column_agent') }}</x-th>
                <x-th>{{ __('backoffice.audit.column_summary') }}</x-th>
                <x-th align="end"><span class="sr-only">{{ __('backoffice.audit.show_detail') }}</span></x-th>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="audit-{{ $row->id }}" class="transition-colors hover:bg-surface">
                    <x-td nowrap mono muted>{{ $row->occurred_at->translatedFormat('d/m/Y H:i') }}</x-td>
                    <x-td nowrap>
                        <x-badge :classes="\App\Enums\AuditAction::badgeClassesFor($row->action)">
                            {{ \App\Enums\AuditAction::labelFor($row->action) }}
                        </x-badge>
                    </x-td>
                    <x-td nowrap>
                        @if ($row->user === null)
                            <span class="text-xs text-muted">{{ __('backoffice.audit.system_agent') }}</span>
                        @else
                            <span class="text-[13px] text-ink">{{ $row->user->fullName() }}</span>
                        @endif
                    </x-td>
                    {{-- La phrase est figée depuis les faits : on l'affiche
                         telle quelle, sans la recomposer. --}}
                    <x-td>{{ $row->summary }}</x-td>
                    <x-td align="end" nowrap>
                        @if ($this->hasDetail($row))
                            <x-button variant="secondary" size="sm"
                                      wire:click="toggleDetail('{{ $row->id }}')" target="toggleDetail"
                                      :aria-expanded="$expanded === $row->id ? 'true' : 'false'"
                                      :aria-label="$expanded === $row->id ? __('backoffice.audit.hide_detail') : __('backoffice.audit.show_detail')">
                                <svg @class(['size-4 transition-transform', 'rotate-180' => $expanded === $row->id])
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </x-button>
                        @endif
                    </x-td>
                </tr>

                @if ($expanded === $row->id)
                    <tr wire:key="audit-detail-{{ $row->id }}" class="bg-surface">
                        <td colspan="99" class="border-b border-line px-4 py-4">
                            <x-dl :cols="3">
                                <x-dl-item :term="__('backoffice.audit.detail_ip')" mono>
                                    {{ $row->ip_address ?? __('backoffice.audit.detail_none') }}
                                </x-dl-item>
                                {{-- L'alias de morph et l'identifiant, pas le
                                     modèle : la cible a pu être supprimée. --}}
                                <x-dl-item :term="__('backoffice.audit.detail_subject')" mono>
                                    {{ $row->subject_type === null ? __('backoffice.audit.detail_none') : $row->subject_type.' · '.$row->subject_id }}
                                </x-dl-item>
                                <x-dl-item :term="__('backoffice.audit.detail_driver')">
                                    {{ $row->driver?->fullName() ?? __('backoffice.audit.detail_none') }}
                                </x-dl-item>
                                @foreach ($this->contextRows($row) as $index => $fact)
                                    <x-dl-item wire:key="audit-{{ $row->id }}-fact-{{ $index }}" :term="$fact['term']" mono>
                                        {{ $fact['value'] }}
                                    </x-dl-item>
                                @endforeach
                            </x-dl>
                        </td>
                    </tr>
                @endif
            @endforeach

            @if ($rows->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="neutral" :title="__('backoffice.audit.no_rows')" :hint="__('backoffice.audit.no_rows_hint')">
                        <x-slot:action>
                            <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">
                                {{ __('backoffice.audit.reset_filters') }}
                            </x-button>
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
