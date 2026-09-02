{{--
    File de traitement du support.

    Deux colonnes : la file à gauche, le fil à droite, chacune défilant dans
    son propre cadre pour que l'écran tienne dans la fenêtre. Le fil montre
    *tout* l'historique du conducteur, pas seulement le ticket courant —
    l'agent ne doit pas le faire répéter.

    Rafraîchissement : Echo écoute la file et le fil ouvert, et demande au
    composant de se recharger — la trame reçue n'est qu'un signal, jamais la
    source du rendu, pour que rien ne s'affiche qui n'ait été autorisé côté
    serveur.

    `wire:poll.60s` reste en filet : un websocket tombé ne doit pas faire
    disparaître une requête, et l'écran fonctionne tel quel si Reverb n'est pas
    déployé.
--}}
<div wire:poll.60s
     x-data="supportRealtime(@js($selected))">

    {{-- ------------------------------------------------------------ --}}
    {{-- Santé de la file, avant d'ouvrir quoi que ce soit.            --}}
    {{-- ------------------------------------------------------------ --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-start gap-3.5 rounded border border-line bg-card p-4 shadow-sm">
            <span class="flex size-10 shrink-0 items-center justify-center rounded bg-warn-bg text-warn-text">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.kpi_triage') }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight text-ink">{{ number_format($triageCount) }}</p>
            </div>
        </div>

        <div class="flex items-start gap-3.5 rounded border border-line bg-card p-4 shadow-sm">
            <span class="flex size-10 shrink-0 items-center justify-center rounded bg-primary-tint text-primary-text">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.kpi_tickets') }}</p>
                <div class="mt-1 flex items-end justify-between gap-3">
                    <p class="text-3xl font-semibold tracking-tight text-ink">{{ number_format($ticketCount) }}</p>
                    {{-- Sept barres, sept jours, la dernière étant aujourd'hui :
                         la tendance se lit sans légende. --}}
                    @php $peak = max(1, max($openedPerDay)); @endphp
                    <div class="flex h-8 items-end gap-1" aria-hidden="true">
                        @foreach ($openedPerDay as $i => $n)
                            <span @class(['w-1.5 rounded-sm', 'bg-primary' => $loop->last, 'bg-primary/35' => ! $loop->last])
                                  style="height: {{ max(12, (int) round($n / $peak * 100)) }}%"></span>
                        @endforeach
                    </div>
                </div>
                <p class="mt-0.5 text-[11px] text-muted">{{ array_sum($openedPerDay) }} {{ __('backoffice.support_requests.kpi_opened_7d') }}</p>
            </div>
        </div>

        {{-- Le rouge signale le manquement, jamais un simple décompte : à
             zéro la carte reste neutre. --}}
        <div @class([
            'relative flex items-start gap-3.5 overflow-hidden rounded border bg-card p-4 shadow-sm',
            'border-err-text/30' => $breachedCount > 0,
            'border-line' => $breachedCount === 0,
        ])>
            @if ($breachedCount > 0)
                <span class="absolute inset-y-0 left-0 w-1 bg-err-text" aria-hidden="true"></span>
            @endif
            <span @class([
                'flex size-10 shrink-0 items-center justify-center rounded',
                'bg-err-bg text-err-text' => $breachedCount > 0,
                'bg-ok-bg text-ok-text' => $breachedCount === 0,
            ])>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/></svg>
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.kpi_breached') }}</p>
                <p class="mt-1 flex items-center gap-2 text-3xl font-semibold tracking-tight {{ $breachedCount > 0 ? 'text-err-text' : 'text-ink' }}">
                    {{ number_format($breachedCount) }}
                    @if ($breachedCount > 0)
                        <span class="size-2.5 rounded-full bg-err-text animate-pulse-soft" aria-hidden="true"></span>
                    @endif
                </p>
                <p class="mt-0.5 text-[11px] text-muted">
                    {{ $breachedCount > 0 ? __('backoffice.support_requests.kpi_breached_hint') : __('backoffice.support_requests.kpi_all_good') }}
                </p>
            </div>
        </div>

        <div class="flex items-start gap-3.5 rounded border border-line bg-card p-4 shadow-sm">
            <span class="flex size-10 shrink-0 items-center justify-center rounded bg-neutral-bg text-neutral-text">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.kpi_mine') }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight text-ink">{{ number_format($mineCount) }}</p>
            </div>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-1.5">
        <x-chip-filter wire:click="$set('tab', 'triage')" :active="$tab === 'triage'" :count="$triageCount">
            {{ __('backoffice.support_requests.tab_triage') }}
        </x-chip-filter>
        <x-chip-filter wire:click="$set('tab', 'tickets')" :active="$tab === 'tickets'" :count="$ticketCount">
            {{ __('backoffice.support_requests.tab_tickets') }}
        </x-chip-filter>
    </div>

    {{-- Hauteur calée sur la fenêtre à partir de `lg` : chaque volet défile
         dans son cadre, le compositeur reste sous la main. En dessous, tout
         s'empile et la page défile normalement. --}}
    <div class="mt-4 grid gap-5 lg:h-[calc(100dvh-22.5rem)] lg:min-h-[34rem] lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">

        {{-- ------------------------------------------------------------ --}}
        {{-- Colonne de gauche : la file.                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <section class="flex min-h-0 flex-col overflow-hidden rounded border border-line bg-card shadow-sm"
                 aria-label="{{ $tab === 'triage' ? __('backoffice.support_requests.tab_triage') : __('backoffice.support_requests.tab_tickets') }}">
            <div class="shrink-0 border-b border-line px-4 pb-3 pt-4">
                <div class="flex items-baseline gap-2">
                    <h2 class="text-[15px] font-bold text-ink">
                        {{ $tab === 'triage' ? __('backoffice.support_requests.tab_triage') : __('backoffice.support_requests.tab_tickets') }}
                    </h2>
                    <span class="text-[11px] font-semibold text-muted">{{ $rows->total() }}</span>
                </div>

                <div class="relative mt-3">
                    <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input wire:model.live.debounce.400ms="search" type="search"
                           placeholder="{{ __('backoffice.support_requests.search_placeholder') }}"
                           class="w-full rounded border border-input bg-surface py-2 pl-9 pr-3 text-sm placeholder:text-muted focus:border-primary focus:bg-card">
                </div>

                @if ($tab === 'tickets')
                    {{-- Jetons de filtre : `x-chip-filter` et non du markup en
                         ligne (cf. .ai/rules/components.md). --}}
                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <x-chip-filter wire:click="filterByStatus(null)" :active="$status === null">
                            {{ __('backoffice.support_requests.all') }}
                        </x-chip-filter>
                        @foreach (\App\Enums\SupportRequestStatus::cases() as $case)
                            <x-chip-filter wire:key="status-{{ $case->value }}"
                                           wire:click="filterByStatus('{{ $case->value }}')"
                                           :active="$status === $case->value"
                                           :title="$case->label().' — '.$case->hint()">
                                {{ $case->label() }}
                            </x-chip-filter>
                        @endforeach
                        <span class="flex-1"></span>
                        <x-chip-filter wire:click="toggleAssignedToMe" :active="$assigned === 'me'">
                            {{ __('backoffice.support_requests.assigned_to_me') }}
                        </x-chip-filter>
                        <x-chip-filter wire:click="toggleBreachedOnly" :active="$breachedOnly" tone="danger">
                            {{ __('backoffice.support_requests.breached_only') }}
                        </x-chip-filter>
                    </div>

                    {{-- Légende : « Ouverte »/« En attente » et « Résolue »/« Fermée »
                         se lisent sinon comme deux paires de synonymes. Le texte
                         vient de l'énumération, pas d'ici. --}}
                    <dl class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[11px] leading-snug text-muted">
                        @foreach (\App\Enums\SupportRequestStatus::cases() as $case)
                            <div wire:key="legend-{{ $case->value }}" class="flex items-baseline gap-1">
                                <dt class="font-semibold text-ink">{{ $case->label() }}</dt>
                                <dd>{{ $case->hint() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto transition-opacity"
                 wire:loading.class="opacity-50"
                 wire:target="filterByStatus,toggleAssignedToMe,toggleBreachedOnly,search,gotoPage,previousPage,nextPage">
                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center px-4 py-14 text-center">
                        <span class="flex size-11 items-center justify-center rounded-full bg-ok-bg text-ok-text">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <p class="mt-3 text-sm text-muted">
                            {{ $tab === 'triage'
                                ? __('backoffice.support_requests.empty_triage')
                                : __('backoffice.support_requests.empty_tickets') }}
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($rows as $row)
                            @php
                                /* Sur l'onglet « Tickets » la ligne est un ticket : c'est
                                   sa conversation qu'il faut ouvrir, pas le ticket. */
                                $conversationId = $tab === 'triage' ? $row->id : $row->conversation_id;
                                $isSelected = $selected === $conversationId;
                                $stripe = $tab === 'triage'
                                    ? 'bg-warn-text'
                                    : match ($row->priority) {
                                        \App\Enums\SupportRequestPriority::High => 'bg-err-text',
                                        \App\Enums\SupportRequestPriority::Normal => 'bg-warn-text',
                                        default => 'bg-line',
                                    };
                            @endphp
                            {{-- `wire:key` obligatoire : la file se réordonne à chaque
                                 message, sans clé le diff DOM recycle les lignes et
                                 mélange leur contenu. --}}
                            <li wire:key="row-{{ $tab }}-{{ $row->id }}" class="animate-fade-up">
                                {{-- `aria-current` en plus de la teinte : la sélection
                                     ne doit pas tenir qu'à une couleur, c'est elle qui
                                     dit quel fil est ouvert à droite. --}}
                                <button type="button" wire:click="select('{{ $conversationId }}')"
                                        @if ($isSelected) aria-current="true" @endif
                                        @class([
                                            'relative flex w-full gap-3 py-3 pl-4 pr-4 text-left transition-colors',
                                            'bg-primary-tint' => $isSelected,
                                            'hover:bg-surface' => ! $isSelected,
                                        ])>
                                    {{-- Liseré : l'attente sur la file de tri, la
                                         priorité sur les tickets. --}}
                                    <span class="absolute inset-y-2 left-0 w-[3px] rounded-r {{ $stripe }}" aria-hidden="true"></span>

                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-tint text-xs font-semibold text-primary-text">
                                        {{ $row->driver?->initials() ?? '—' }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        @if ($tab === 'triage')
                                            <span class="flex items-baseline gap-2">
                                                <b class="truncate text-[13px] text-ink">{{ $row->driver?->fullName() }}</b>
                                                <span class="flex-1"></span>
                                                {{-- La file est du plus ancien au plus récent :
                                                     l'attente est l'information qui la justifie. --}}
                                                <span class="shrink-0 text-[11px] font-semibold text-warn-text">
                                                    {{ $row->last_message_at?->diffForHumans(['short' => true]) }}
                                                </span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-muted">
                                                {{ $row->last_message_preview ?: __('backoffice.support_requests.no_preview') }}
                                            </span>
                                            @if ($row->last_message_sender_type === 'driver')
                                                <span class="mt-1.5 inline-flex items-center gap-1.5 text-[11px] font-semibold text-warn-text">
                                                    <span class="size-1.5 rounded-full bg-warn-text" aria-hidden="true"></span>
                                                    {{ __('backoffice.support_requests.awaiting_reply') }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="flex items-baseline gap-2">
                                                <span class="font-mono text-[11px] font-semibold text-muted">#{{ $row->number }}</span>
                                                <b class="truncate text-[13px] text-ink">{{ $row->driver?->fullName() }}</b>
                                                <span class="flex-1"></span>
                                                @if ($row->staff_unread_count > 0)
                                                    <span class="shrink-0 rounded-full bg-primary px-2 py-0.5 text-[10.5px] font-bold text-white">
                                                        {{ $row->staff_unread_count }}
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-muted">
                                                {{ $row->subject ?: __('backoffice.support_requests.no_subject') }}
                                            </span>
                                            <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                <span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $row->status->badgeClasses() }}">
                                                    {{ $row->status->label() }}
                                                </span>
                                                <span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $row->priority->badgeClasses() }}">
                                                    {{ $row->priority->label() }}
                                                </span>
                                                <span class="rounded-full bg-neutral-bg px-2 py-0.5 text-[10.5px] font-semibold text-neutral-text">
                                                    {{ $row->category->label() }}
                                                </span>
                                                <span class="flex-1"></span>
                                                <span class="truncate text-[11px] text-muted">
                                                    {{ $row->assignedUser?->name ?? __('backoffice.support_requests.unassigned') }}
                                                </span>
                                            </span>

                                            {{-- Jauge du chronomètre en cours. Vert tant qu'il
                                                 reste du temps, ambre au-delà de 70 %, rouge
                                                 dépassé — le badge « En retard » n'a plus à le dire. --}}
                                            @php $gauge = $row->slaProgress(); @endphp
                                            @if ($gauge !== null)
                                                @php
                                                    /* Classes écrites en entier : Tailwind ne génère que ce
                                                       qu'il lit littéralement dans la source. */
                                                    [$barTone, $textTone] = $gauge['overdue']
                                                        ? ['bg-err-text', 'text-err-text']
                                                        : ($gauge['ratio'] >= 0.7 ? ['bg-warn-text', 'text-warn-text'] : ['bg-ok-text', 'text-ok-text']);
                                                    $phase = __('backoffice.support_requests.sla_'.$gauge['phase']);
                                                    $when = $gauge['due']->longAbsoluteDiffForHumans(now(), 2);
                                                @endphp
                                                <span class="mt-2 flex items-center gap-2">
                                                    <span class="h-1 flex-1 overflow-hidden rounded-full bg-neutral-bg" aria-hidden="true">
                                                        <span class="block h-full rounded-full {{ $barTone }} transition-[width]"
                                                              style="width: {{ (int) round($gauge['ratio'] * 100) }}%"></span>
                                                    </span>
                                                    <span class="shrink-0 text-[10.5px] font-semibold tabular-nums {{ $textTone }}">
                                                        {{ $phase }} ·
                                                        {{ $gauge['overdue']
                                                            ? __('backoffice.support_requests.sla_overdue', ['time' => $when])
                                                            : __('backoffice.support_requests.sla_remaining', ['time' => $when]) }}
                                                    </span>
                                                </span>
                                            @endif
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($rows->hasPages())
                <div class="shrink-0 border-t border-line px-4 py-3">
                    {{ $rows->links() }}
                </div>
            @endif
        </section>

        {{-- ------------------------------------------------------------ --}}
        {{-- Colonne de droite : le fil.                                   --}}
        {{-- ------------------------------------------------------------ --}}
        <section class="flex min-h-0 flex-col overflow-hidden rounded border border-line bg-card shadow-sm">
            @if ($conversation === null)
                <div class="flex flex-1 flex-col items-center justify-center px-5 py-16 text-center">
                    <span class="flex size-14 items-center justify-center rounded-full bg-primary-tint text-primary-text">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </span>
                    <p class="mt-4 text-sm font-semibold text-ink">{{ __('backoffice.support_requests.pick_conversation') }}</p>
                    <p class="mt-1 max-w-xs text-xs text-muted">{{ __('backoffice.support_requests.pick_conversation_hint') }}</p>
                </div>
            @else
                <div class="flex shrink-0 flex-wrap items-center gap-3.5 border-b border-line px-5 py-3.5">
                    @if ($conversation->driver?->photo_url)
                        <img src="{{ route('bo.drivers.photo', $conversation->driver) }}" alt=""
                             class="size-11 shrink-0 rounded-full object-cover">
                    @else
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-semibold text-primary-text">
                            {{ $conversation->driver?->initials() ?? '—' }}
                        </span>
                    @endif
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-[15px] font-bold text-ink">
                            <span class="truncate">{{ $conversation->driver?->fullName() }}</span>
                            @if ($conversation->driver !== null)
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $conversation->driver->status->badgeClasses() }}">
                                    {{ $conversation->driver->status->label() }}
                                </span>
                            @endif
                        </p>
                        <p class="font-mono text-xs text-muted">{{ $conversation->driver?->phone }}</p>
                    </div>
                    <span class="flex-1"></span>
                    @if ($conversation->driver !== null)
                        <a href="{{ route('bo.drivers.show', $conversation->driver) }}" wire:navigate
                           class="inline-flex items-center gap-1 text-xs font-semibold text-primary-text hover:underline">
                            {{ __('backoffice.support_requests.view_driver') }}
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </a>
                    @endif
                </div>

                @if ($untriagedCount > 0)
                    <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-warn-text/20 bg-warn-bg px-5 py-2.5">
                        <span class="size-2 rounded-full bg-warn-text animate-pulse-soft" aria-hidden="true"></span>
                        <p class="text-xs font-semibold text-warn-text">
                            {{ trans_choice('backoffice.support_requests.untriaged_banner', $untriagedCount, ['count' => $untriagedCount]) }}
                        </p>
                        <span class="flex-1"></span>
                        <x-button size="sm" wire:click="openTicketForm" target="openTicketForm">
                            {{ __('backoffice.support_requests.create_ticket') }}
                        </x-button>
                        {{-- Ce geste n'envoie rien : il clôt le tri. Répondre se
                             fait dans le compositeur en bas du fil, et laisse
                             la conversation ici jusqu'à ce que l'agent tranche. --}}
                        <x-button variant="secondary" size="sm"
                                  wire:click="confirmDismiss('{{ $conversation->id }}')"
                                  target="confirmDismiss">
                            {{ __('backoffice.support_requests.dismiss') }}
                        </x-button>
                    </div>
                @endif

                @if ($liveRequest !== null)
                    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-line bg-surface px-5 py-2.5">
                        <span class="font-mono text-[11px] font-semibold text-muted">#{{ $liveRequest->number }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $liveRequest->status->badgeClasses() }}">
                            {{ $liveRequest->status->label() }}
                        </span>
                        <span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $liveRequest->priority->badgeClasses() }}">
                            {{ $liveRequest->priority->label() }}
                        </span>
                        @if ($sla->isBreached($liveRequest))
                            <span class="inline-flex items-center gap-1 rounded-full bg-err-bg px-2 py-0.5 text-[10.5px] font-semibold text-err-text">
                                <span class="size-1.5 rounded-full bg-err-text animate-pulse-soft" aria-hidden="true"></span>
                                {{ __('backoffice.support_requests.late') }}
                            </span>
                        @endif

                        <span class="flex-1"></span>

                        <label class="sr-only" for="recategorise">{{ __('backoffice.support_requests.category') }}</label>
                        <select id="recategorise" wire:change="recategorise($event.target.value)"
                                wire:loading.attr="disabled" wire:target="recategorise"
                                class="rounded border border-input bg-card px-2.5 py-1.5 text-xs text-ink focus:border-primary disabled:opacity-60">
                            @foreach (\App\Enums\SupportRequestCategory::cases() as $case)
                                <option value="{{ $case->value }}" @selected($liveRequest->category === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        {{-- Redistribuer est un acte d'encadrement : la
                             direction seule voit ce sélecteur, et le composant
                             revérifie l'autorisation côté serveur. --}}
                        @can('reassignSupportRequest')
                            <label class="sr-only" for="reassign">{{ __('backoffice.support_requests.reassign') }}</label>
                            <select id="reassign" wire:change="reassign($event.target.value)"
                                    wire:loading.attr="disabled" wire:target="reassign"
                                    class="rounded border border-input bg-card px-2.5 py-1.5 text-xs text-ink focus:border-primary disabled:opacity-60">
                                <option value="" @selected($liveRequest->assigned_user_id === null)>
                                    {{ __('backoffice.support_requests.unassigned') }}
                                </option>
                                @foreach ($assignableAgents as $agent)
                                    <option wire:key="agent-{{ $agent->id }}" value="{{ $agent->id }}"
                                            @selected($liveRequest->assigned_user_id === $agent->id)>
                                        {{ $agent->fullName() }}
                                    </option>
                                @endforeach
                            </select>
                        @endcan
                        {{-- Reprendre un ticket à son compte n'est pas
                             redistribuer : ouvert à tous les agents. --}}
                        <x-button variant="secondary" size="sm" wire:click="assignToMe" target="assignToMe">
                            {{ __('backoffice.support_requests.assign_to_me') }}
                        </x-button>
                        <x-button size="sm" wire:click="confirmResolve('{{ $liveRequest->id }}')" target="confirmResolve">
                            {{ __('backoffice.support_requests.resolve') }}
                        </x-button>
                    </div>
                @endif

                {{-- Historique des tickets, en une ligne de jetons : l'agent
                     voit d'un coup d'œil ce que le conducteur a déjà demandé. --}}
                @if ($history->isNotEmpty())
                    <div class="flex shrink-0 flex-wrap items-center gap-1.5 border-b border-line px-5 py-2.5">
                        <span class="mr-1 text-[10.5px] font-semibold uppercase tracking-wide text-muted">
                            {{ __('backoffice.support_requests.history_title') }}
                        </span>
                        @foreach ($history as $past)
                            <span wire:key="history-{{ $past->id }}"
                                  class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-1 text-[11px]"
                                  title="{{ $past->subject ?: __('backoffice.support_requests.no_subject') }} — {{ $past->created_at?->format('d/m/Y') }}">
                                <span class="font-mono font-semibold text-muted">#{{ $past->number }}</span>
                                <span class="size-1.5 rounded-full {{ $past->status->badgeClasses() }}" aria-hidden="true"></span>
                                <span class="text-muted">{{ $past->status->label() }}</span>
                                <span class="hidden truncate text-ink sm:inline">· {{ \Illuminate\Support\Str::limit($past->subject ?: __('backoffice.support_requests.no_subject'), 36) }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Le fil se lit du plus ancien au plus récent : sans ce
                     défilement, un message qui arrive atterrit sous la ligne
                     de flottaison et passe inaperçu. --}}
                <div x-ref="thread"
                     x-init="$nextTick(() => $refs.thread.scrollTop = $refs.thread.scrollHeight)"
                     x-on:thread-updated.window="$nextTick(() => $refs.thread.scrollTop = $refs.thread.scrollHeight)"
                     class="min-h-0 flex-1 space-y-1 overflow-y-auto bg-surface/60 px-5 py-4 max-h-[28rem] lg:max-h-none">
                    @if ($hasOlder)
                        <div class="pb-2 text-center">
                            <x-button variant="secondary" size="sm" wire:click="loadOlder" target="loadOlder">
                                {{ __('backoffice.support_requests.load_older') }}
                            </x-button>
                        </div>
                    @endif

                    @php $previousDay = null; $previousSender = null; $previousAt = null; @endphp
                    @forelse ($thread as $message)
                        @php
                            $day = $message->created_at?->toDateString();
                            $newDay = $day !== $previousDay;
                            $senderKey = $message->isSystem() ? null : $message->sender_type.'|'.$message->sender_id;
                            /* Messages du même émetteur à moins de cinq minutes : une
                               seule en-tête, des bulles serrées — comme une conversation. */
                            $continues = ! $newDay
                                && $senderKey !== null
                                && $senderKey === $previousSender
                                && $previousAt !== null
                                && $message->created_at?->diffInMinutes($previousAt, true) < 5;
                            $isStaff = $message->sender_type === 'user';
                        @endphp

                        @if ($newDay && $message->created_at !== null)
                            <div wire:key="day-{{ $day }}" class="flex items-center gap-3 py-3">
                                <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
                                <span class="text-[10.5px] font-semibold uppercase tracking-wide text-muted">
                                    @if ($message->created_at->isToday())
                                        {{ __('backoffice.support_requests.today') }}
                                    @elseif ($message->created_at->isYesterday())
                                        {{ __('backoffice.support_requests.yesterday') }}
                                    @else
                                        {{ $message->created_at->translatedFormat('l j F') }}
                                    @endif
                                </span>
                                <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
                            </div>
                        @endif

                        {{-- `wire:key` obligatoire : `loadOlder()` préfixe le fil, et sans
                             clé le diff DOM réutilise les bulles en décalant leur contenu. --}}
                        @if ($message->isSystem())
                            <div wire:key="msg-{{ $message->id }}" class="flex justify-center py-1.5">
                                <p class="max-w-[85%] rounded-full bg-neutral-bg px-3 py-1 text-center text-[11px] text-neutral-text">
                                    {{ $message->body }}
                                    <span class="ml-1 opacity-60">{{ $message->created_at?->format('H:i') }}</span>
                                </p>
                            </div>
                        @else
                            <div wire:key="msg-{{ $message->id }}" @class([
                                'flex items-end gap-2',
                                'justify-end' => $isStaff,
                                'justify-start' => ! $isStaff,
                                'mt-3' => ! $continues,
                                'mt-0.5' => $continues,
                            ])>
                                @unless ($isStaff)
                                    {{-- Avatar sur la première bulle d'une série, un
                                         espace de même largeur sur les suivantes. --}}
                                    <span @class([
                                        'flex size-7 shrink-0 items-center justify-center rounded-full text-[10.5px] font-semibold',
                                        'bg-primary-tint text-primary-text' => ! $continues,
                                        'invisible' => $continues,
                                    ])>{{ $conversation->driver?->initials() ?? '—' }}</span>
                                @endunless

                                <div @class([
                                    'max-w-[78%] px-3.5 py-2 shadow-sm',
                                    'rounded-2xl rounded-br-md bg-primary text-white' => $isStaff,
                                    'rounded-2xl rounded-bl-md border border-line bg-card text-ink' => ! $isStaff,
                                ])>
                                    @if ($isStaff && ! $continues)
                                        <p class="text-[10.5px] font-semibold text-white/80">{{ $message->sender_name }}</p>
                                    @endif
                                    @if ($message->body !== null)
                                        <p class="whitespace-pre-line text-[13px] leading-relaxed">{{ $message->body }}</p>
                                    @endif
                                    {{-- Pièces jointes : une image se montre, le reste se
                                         propose. Toujours via la route protégée, jamais le
                                         chemin du disque. --}}
                                    @foreach ($message->attachments as $attachment)
                                        @php
                                            $attachmentUrl = route('bo.support-requests.attachment', $attachment);
                                            $attachmentLabel = __('backoffice.support_requests.open_attachment', ['name' => $attachment->original_name, 'size' => $attachment->humanSize()]);
                                        @endphp
                                        @if ($attachment->isImage())
                                            <a wire:key="att-{{ $attachment->id }}" href="{{ $attachmentUrl }}" target="_blank" rel="noopener"
                                               title="{{ $attachmentLabel }}"
                                               class="mt-1.5 block overflow-hidden rounded-lg border border-black/10 transition-opacity hover:opacity-90">
                                                <img src="{{ $attachmentUrl }}" alt="{{ $attachment->original_name }}" loading="lazy"
                                                     class="max-h-56 w-auto max-w-full object-cover">
                                            </a>
                                        @else
                                            <a wire:key="att-{{ $attachment->id }}" href="{{ $attachmentUrl }}" target="_blank" rel="noopener"
                                               title="{{ $attachmentLabel }}" @class([
                                                'mt-1.5 flex items-center gap-2 rounded-lg px-2.5 py-2 text-[12px] font-medium transition-colors',
                                                'bg-white/15 text-white hover:bg-white/25' => $isStaff,
                                                'bg-neutral-bg text-ink hover:bg-line' => ! $isStaff,
                                            ])>
                                                <span @class([
                                                    'flex size-8 shrink-0 items-center justify-center rounded',
                                                    'bg-white/20' => $isStaff,
                                                    'bg-card text-primary-text' => ! $isStaff,
                                                ])>
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                                                </span>
                                                <span class="min-w-0">
                                                    <span class="block truncate">{{ $attachment->original_name }}</span>
                                                    <span class="block text-[10.5px] opacity-70">{{ $attachment->humanSize() }}</span>
                                                </span>
                                                <svg class="ml-auto size-3.5 shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                            </a>
                                        @endif
                                    @endforeach
                                    <p @class([
                                        'mt-1 text-right text-[10px] tabular-nums',
                                        'text-white/70' => $isStaff,
                                        'text-muted' => ! $isStaff,
                                    ])>{{ $message->created_at?->format('H:i') }}</p>
                                </div>
                            </div>
                        @endif

                        @php $previousDay = $day; $previousSender = $senderKey; $previousAt = $message->created_at; @endphp
                    @empty
                        <p class="py-8 text-center text-sm text-muted">{{ __('backoffice.support_requests.thread_empty') }}</p>
                    @endforelse
                </div>

                {{-- Trois cas, dans cet ordre : un ticket ouvert se répond
                     dans le ticket ; des messages à trier se répondent sans
                     ticket ; sinon il n'y a rien à écrire. --}}
                <div class="shrink-0 border-t border-line bg-card px-5 py-3.5">
                    @if ($liveRequest === null && $untriagedCount > 0)
                        <p class="mb-2 text-[11px] text-muted">{{ __('backoffice.support_requests.triage_compose_hint') }}</p>

                        {{-- `.blur` et non `.live` : un aller-retour serveur par
                             frappe rendrait la saisie inutilisable. --}}
                        <label class="sr-only" for="triageDraft">{{ __('backoffice.support_requests.triage_compose_placeholder') }}</label>
                        <textarea wire:model.blur="triageDraft" id="triageDraft" rows="2"
                                  wire:keydown.meta.enter="sendTriageReply" wire:keydown.ctrl.enter="sendTriageReply"
                                  placeholder="{{ __('backoffice.support_requests.triage_compose_placeholder') }}"
                                  class="block w-full resize-none rounded border border-input bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-primary focus:bg-card"></textarea>
                        @error('triageDraft') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror

                        <div class="mt-2 flex items-center gap-2.5">
                            <x-button variant="secondary" size="sm" wire:click="$toggle('templatesOpen')">
                                {{ __('backoffice.support_requests.templates') }}
                            </x-button>
                            <span class="flex-1"></span>
                            <kbd class="hidden font-sans text-[10.5px] text-muted sm:inline">{{ __('backoffice.support_requests.shortcut_send') }}</kbd>
                            <x-button wire:click="sendTriageReply" target="sendTriageReply">
                                {{ __('backoffice.support_requests.reply_without_ticket') }}
                                <x-slot:loading>{{ __('backoffice.support_requests.sending') }}</x-slot:loading>
                            </x-button>
                        </div>
                    @elseif ($liveRequest === null)
                        <p class="text-xs text-muted">{{ __('backoffice.support_requests.no_live_request') }}</p>
                    @else
                        <label class="sr-only" for="draft">{{ __('backoffice.support_requests.compose_placeholder') }}</label>
                        <textarea wire:model.blur="draft" id="draft" rows="2"
                                  wire:keydown.meta.enter="send" wire:keydown.ctrl.enter="send"
                                  placeholder="{{ __('backoffice.support_requests.compose_placeholder') }}"
                                  class="block w-full resize-none rounded border border-input bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-primary focus:bg-card"></textarea>
                        @error('draft') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror

                        <div class="mt-2 flex items-center gap-2.5">
                            <x-button variant="secondary" size="sm" wire:click="$toggle('templatesOpen')">
                                {{ __('backoffice.support_requests.templates') }}
                            </x-button>
                            <span class="flex-1"></span>
                            <kbd class="hidden font-sans text-[10.5px] text-muted sm:inline">{{ __('backoffice.support_requests.shortcut_send') }}</kbd>
                            <x-button wire:click="send" target="send">
                                {{ __('backoffice.support_requests.send') }}
                                <x-slot:loading>{{ __('backoffice.support_requests.sending') }}</x-slot:loading>
                            </x-button>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    </div>

    @if ($creatingTicket)
        <x-modal close="cancelTicketForm" align="start" :title="__('backoffice.support_requests.ticket_form_title')">
            <form wire:submit="createTicket" class="space-y-4 px-5 py-4">
                {{-- Pas de champ « priorité » : elle découle de la catégorie
                     via `SlaCalculator`, l'agent ne la choisit jamais. --}}
                <p class="text-xs text-muted">{{ __('backoffice.support_requests.ticket_form_hint') }}</p>

                <div>
                    <label for="ticketCategory" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.support_requests.field_category') }}</label>
                    <select wire:model="ticketCategory" id="ticketCategory"
                            class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @foreach (\App\Enums\SupportRequestCategory::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    @error('ticketCategory') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="ticketSubject" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.support_requests.field_subject') }}</label>
                    <input wire:model="ticketSubject" id="ticketSubject" type="text"
                           placeholder="{{ __('backoffice.support_requests.subject_placeholder') }}"
                           class="block w-full rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">
                    @error('ticketSubject') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                </div>
            </form>

            <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                <x-button variant="secondary" wire:click="cancelTicketForm">
                    {{ __('backoffice.support_requests.cancel') }}
                </x-button>
                <x-button wire:click="createTicket" target="createTicket">
                    {{ __('backoffice.support_requests.create') }}
                    <x-slot:loading>{{ __('backoffice.support_requests.working') }}</x-slot:loading>
                </x-button>
            </div>
        </x-modal>
    @endif

    @if ($confirmingResolve !== null)
        <x-modal close="cancelResolve" max-width="max-w-sm" :label="__('backoffice.support_requests.resolve_title')">
            <div class="px-5 pb-4 pt-5">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.support_requests.resolve_title') }}</p>
                <p class="mt-1.5 text-sm text-muted">{{ __('backoffice.support_requests.resolve_body') }}</p>
            </div>
            <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                <x-button variant="secondary" wire:click="cancelResolve">
                    {{ __('backoffice.support_requests.cancel') }}
                </x-button>
                <x-button wire:click="resolve" target="resolve">
                    {{ __('backoffice.support_requests.resolve') }}
                    <x-slot:loading>{{ __('backoffice.support_requests.working') }}</x-slot:loading>
                </x-button>
            </div>
        </x-modal>
    @endif

    @if ($confirmingDismiss !== null)
        <x-modal close="cancelDismiss" max-width="max-w-sm" :label="__('backoffice.support_requests.dismiss_title')">
            <div class="px-5 pb-4 pt-5">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.support_requests.dismiss_title') }}</p>
                <p class="mt-1.5 text-sm text-muted">{{ __('backoffice.support_requests.dismiss_body') }}</p>
            </div>
            <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                <x-button variant="secondary" wire:click="cancelDismiss">
                    {{ __('backoffice.support_requests.cancel') }}
                </x-button>
                <x-button wire:click="dismiss" target="dismiss">
                    {{ __('backoffice.support_requests.confirm') }}
                    <x-slot:loading>{{ __('backoffice.support_requests.working') }}</x-slot:loading>
                </x-button>
            </div>
        </x-modal>
    @endif

    @if ($templatesOpen)
        <x-modal close="$toggle('templatesOpen')" align="start" :title="__('backoffice.support_requests.templates_title')">
            <div class="max-h-[26rem] overflow-y-auto px-5 py-4">
                @forelse ($templates as $template)
                    <button type="button" wire:key="template-{{ $template->id }}"
                            wire:click="useTemplate('{{ $template->id }}')"
                            class="block w-full rounded border border-line px-3.5 py-2.5 text-left transition-colors hover:border-primary hover:bg-surface [&+&]:mt-2">
                        <span class="flex items-baseline gap-2">
                            <b class="text-[13px] text-ink">{{ $template->title }}</b>
                            @if ($template->shortcut !== null)
                                <span class="font-mono text-[11px] text-muted">{{ $template->shortcut }}</span>
                            @endif
                        </span>
                        <span class="mt-1 block text-xs text-muted">{{ \Illuminate\Support\Str::limit($template->body, 120) }}</span>
                    </button>
                @empty
                    <p class="py-6 text-center text-sm text-muted">{{ __('backoffice.support_requests.templates_empty') }}</p>
                @endforelse
            </div>
        </x-modal>
    @endif
</div>
