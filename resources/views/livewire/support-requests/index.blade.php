{{--
    File de traitement du support.

    Deux colonnes : la file à gauche, le fil à droite. Le fil montre *tout*
    l'historique du conducteur, pas seulement le ticket courant — l'agent ne
    doit pas le faire répéter.

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
    <div class="flex flex-wrap items-center gap-1.5">
        <x-chip-filter wire:click="$set('tab', 'triage')" :active="$tab === 'triage'" :count="$triageCount">
            {{ __('backoffice.support_requests.tab_triage') }}
        </x-chip-filter>
        <x-chip-filter wire:click="$set('tab', 'tickets')" :active="$tab === 'tickets'" :count="$ticketCount">
            {{ __('backoffice.support_requests.tab_tickets') }}
        </x-chip-filter>
    </div>

    <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        {{-- ------------------------------------------------------------ --}}
        {{-- Colonne de gauche : la file.                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <input wire:model.live.debounce.400ms="search" type="search"
                       placeholder="{{ __('backoffice.support_requests.search_placeholder') }}"
                       class="w-full rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary">
            </div>

            @if ($tab === 'tickets')
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <button wire:click="filterByStatus(null)"
                            aria-pressed="{{ $status === null ? 'true' : 'false' }}"
                            @class([
                                'rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                'border-primary bg-primary-tint text-primary-text' => $status === null,
                                'border-line bg-card text-muted hover:border-primary' => $status !== null,
                            ])>
                        {{ __('backoffice.support_requests.all') }}
                    </button>
                    @foreach (\App\Enums\SupportRequestStatus::cases() as $case)
                        <button wire:click="filterByStatus('{{ $case->value }}')"
                                aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}"
                                @class([
                                    'rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                    'border-primary bg-primary-tint text-primary-text' => $status === $case->value,
                                    'border-line bg-card text-muted hover:border-primary' => $status !== $case->value,
                                ])>
                            {{ $case->label() }}
                        </button>
                    @endforeach

                    <span class="flex-1"></span>

                    <button wire:click="toggleAssignedToMe"
                            aria-pressed="{{ $assigned === 'me' ? 'true' : 'false' }}"
                            @class([
                                'rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                'border-primary bg-primary-tint text-primary-text' => $assigned === 'me',
                                'border-line bg-card text-muted hover:border-primary' => $assigned !== 'me',
                            ])>
                        {{ __('backoffice.support_requests.assigned_to_me') }}
                    </button>
                    <button wire:click="toggleBreachedOnly"
                            aria-pressed="{{ $breachedOnly ? 'true' : 'false' }}"
                            @class([
                                'rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                'border-err-text bg-err-bg text-err-text' => $breachedOnly,
                                'border-line bg-card text-muted hover:border-primary' => ! $breachedOnly,
                            ])>
                        {{ __('backoffice.support_requests.breached_only') }}
                    </button>
                </div>
            @endif

            <div class="mt-4 overflow-hidden rounded border border-line bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-surface">
                                <th class="border-b border-line px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">
                                    {{ $tab === 'triage' ? __('backoffice.support_requests.tab_triage') : __('backoffice.support_requests.tab_tickets') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php
                                    /* Sur l'onglet « Tickets » la ligne est un ticket : c'est
                                       sa conversation qu'il faut ouvrir, pas le ticket. */
                                    $conversationId = $tab === 'triage' ? $row->id : $row->conversation_id;
                                    $isSelected = $selected === $conversationId;
                                @endphp
                                {{-- `wire:key` obligatoire : la file se réordonne à chaque
                                     message, sans clé le diff DOM recycle les lignes et
                                     mélange leur contenu. --}}
                                <tr wire:key="row-{{ $tab }}-{{ $row->id }}"
                                    @class([
                                        'cursor-pointer transition-colors',
                                        'bg-primary-tint' => $isSelected,
                                        'hover:bg-surface' => ! $isSelected,
                                    ])>
                                    <td class="border-b border-line p-0">
                                        <button type="button" wire:click="select('{{ $conversationId }}')"
                                                class="block w-full px-4 py-3 text-left">
                                            @if ($tab === 'triage')
                                                <span class="flex items-baseline gap-2">
                                                    <b class="text-[13px] text-ink">{{ $row->driver?->fullName() }}</b>
                                                    <span class="font-mono text-[11px] text-muted">{{ $row->driver?->phone }}</span>
                                                    <span class="flex-1"></span>
                                                    {{-- La file est du plus ancien au plus récent :
                                                         l'attente est l'information qui la justifie. --}}
                                                    <span class="shrink-0 text-[11px] font-semibold text-warn-text">
                                                        {{ $row->last_message_at?->diffForHumans() }}
                                                    </span>
                                                </span>
                                                <span class="mt-1 block truncate text-xs text-muted">
                                                    {{ $row->last_message_preview ?: __('backoffice.support_requests.no_preview') }}
                                                </span>
                                            @else
                                                <span class="flex items-baseline gap-2">
                                                    <span class="font-mono text-[11px] font-semibold text-muted">#{{ $row->number }}</span>
                                                    <b class="text-[13px] text-ink">{{ $row->driver?->fullName() }}</b>
                                                    <span class="flex-1"></span>
                                                    @if ($row->staff_unread_count > 0)
                                                        <span class="shrink-0 rounded-full bg-primary px-2 py-0.5 text-[10.5px] font-semibold text-white">
                                                            {{ $row->staff_unread_count }}
                                                        </span>
                                                    @endif
                                                </span>
                                                <span class="mt-1 block truncate text-xs text-muted">
                                                    {{ $row->subject ?: __('backoffice.support_requests.no_subject') }}
                                                </span>
                                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $row->status->badgeClasses() }}">
                                                        {{ $row->status->label() }}
                                                    </span>
                                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $row->priority->badgeClasses() }}">
                                                        {{ $row->priority->label() }}
                                                    </span>
                                                    <span class="rounded-full bg-neutral-bg px-2.5 py-1 text-[11px] font-semibold text-neutral-text">
                                                        {{ $row->category->label() }}
                                                    </span>
                                                    @if ($sla->isBreached($row))
                                                        <span class="rounded-full bg-err-bg px-2.5 py-1 text-[11px] font-semibold text-err-text">
                                                            {{ __('backoffice.support_requests.late') }}
                                                        </span>
                                                    @endif
                                                    <span class="text-[11px] text-muted">
                                                        {{ $row->assignedUser?->name ?? __('backoffice.support_requests.unassigned') }}
                                                    </span>
                                                </span>
                                            @endif
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 py-10 text-center text-sm text-muted">
                                        {{ $tab === 'triage'
                                            ? __('backoffice.support_requests.empty_triage')
                                            : __('backoffice.support_requests.empty_tickets') }}
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

        {{-- ------------------------------------------------------------ --}}
        {{-- Colonne de droite : le fil.                                   --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="overflow-hidden rounded border border-line bg-card">
            @if ($conversation === null)
                <div class="px-5 py-16 text-center">
                    <p class="text-sm font-semibold text-ink">{{ __('backoffice.support_requests.pick_conversation') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('backoffice.support_requests.pick_conversation_hint') }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-ink">{{ $conversation->driver?->fullName() }}</p>
                        <p class="font-mono text-xs text-muted">{{ $conversation->driver?->phone }}</p>
                    </div>
                    <span class="flex-1"></span>
                    @if ($conversation->driver !== null)
                        <a href="{{ route('bo.drivers.show', $conversation->driver) }}" wire:navigate
                           class="text-xs font-semibold text-primary-text hover:underline">
                            {{ __('backoffice.support_requests.view_driver') }}
                        </a>
                    @endif
                </div>

                @if ($untriagedCount > 0)
                    <div class="flex flex-wrap items-center gap-3 border-b border-line bg-warn-bg px-5 py-3">
                        <p class="text-xs font-semibold text-warn-text">
                            {{ trans_choice('backoffice.support_requests.untriaged_banner', $untriagedCount, ['count' => $untriagedCount]) }}
                        </p>
                        <span class="flex-1"></span>
                        <button wire:click="openTicketForm"
                                class="rounded bg-primary px-3.5 py-2 text-xs font-semibold text-white hover:bg-primary-hover">
                            {{ __('backoffice.support_requests.create_ticket') }}
                        </button>
                        <button wire:click="confirmDismiss('{{ $conversation->id }}')"
                                class="rounded border border-line bg-card px-3.5 py-2 text-xs font-semibold text-muted hover:bg-surface">
                            {{ __('backoffice.support_requests.reply_without_ticket') }}
                        </button>
                    </div>
                @endif

                {{-- Historique des tickets : l'agent voit d'un coup d'œil ce que
                     le conducteur a déjà demandé. --}}
                <div class="border-b border-line px-5 py-3">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wide text-muted">
                        {{ __('backoffice.support_requests.history_title') }}
                    </p>
                    @if ($history->isEmpty())
                        <p class="mt-1.5 text-xs text-muted">{{ __('backoffice.support_requests.history_empty') }}</p>
                    @else
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($history as $past)
                                <li wire:key="history-{{ $past->id }}" class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-mono text-[11px] font-semibold text-muted">#{{ $past->number }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $past->status->badgeClasses() }}">
                                        {{ $past->status->label() }}
                                    </span>
                                    <span class="text-[11px] text-muted">{{ $past->category->label() }}</span>
                                    <span class="min-w-0 flex-1 truncate text-ink">{{ $past->subject ?: __('backoffice.support_requests.no_subject') }}</span>
                                    <span class="shrink-0 text-[11px] text-muted">{{ $past->created_at?->format('d/m/Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($liveRequest !== null)
                    <div class="flex flex-wrap items-center gap-2.5 border-b border-line bg-surface px-5 py-3">
                        <span class="font-mono text-[11px] font-semibold text-muted">#{{ $liveRequest->number }}</span>
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $liveRequest->status->badgeClasses() }}">
                            {{ $liveRequest->status->label() }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $liveRequest->priority->badgeClasses() }}">
                            {{ $liveRequest->priority->label() }}
                        </span>
                        @if ($sla->isBreached($liveRequest))
                            <span class="rounded-full bg-err-bg px-2.5 py-1 text-[11px] font-semibold text-err-text">
                                {{ __('backoffice.support_requests.late') }}
                            </span>
                        @endif

                        <span class="flex-1"></span>

                        <label class="sr-only" for="recategorise">{{ __('backoffice.support_requests.category') }}</label>
                        <select id="recategorise" wire:change="recategorise($event.target.value)"
                                class="rounded border border-input bg-card px-2.5 py-1.5 text-xs text-ink focus:border-primary">
                            @foreach (\App\Enums\SupportRequestCategory::cases() as $case)
                                <option value="{{ $case->value }}" @selected($liveRequest->category === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        <button wire:click="assignToMe"
                                class="rounded border border-line bg-card px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface">
                            {{ __('backoffice.support_requests.assign_to_me') }}
                        </button>
                        <button wire:click="confirmResolve('{{ $liveRequest->id }}')"
                                class="rounded bg-primary px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-primary-hover">
                            {{ __('backoffice.support_requests.resolve') }}
                        </button>
                    </div>
                @endif

                {{-- Le fil se lit du plus ancien au plus récent : sans ce
                     défilement, un message qui arrive atterrit sous la ligne
                     de flottaison et passe inaperçu. --}}
                <div x-ref="thread"
                     x-init="$nextTick(() => $refs.thread.scrollTop = $refs.thread.scrollHeight)"
                     x-on:thread-updated.window="$nextTick(() => $refs.thread.scrollTop = $refs.thread.scrollHeight)"
                     class="max-h-[28rem] space-y-3 overflow-y-auto px-5 py-4">
                    @if ($hasOlder)
                        <div class="text-center">
                            <button wire:click="loadOlder"
                                    class="rounded border border-line bg-card px-3.5 py-1.5 text-xs font-semibold text-muted hover:bg-surface">
                                {{ __('backoffice.support_requests.load_older') }}
                            </button>
                        </div>
                    @endif

                    @forelse ($thread as $message)
                        {{-- `wire:key` obligatoire : `loadOlder()` préfixe le fil, et sans
                             clé le diff DOM réutilise les bulles en décalant leur contenu. --}}
                        @if ($message->isSystem())
                            <div wire:key="msg-{{ $message->id }}" class="text-center">
                                <p class="text-[11px] text-muted">
                                    {{ $message->body }}
                                    <span class="ml-1 opacity-70">{{ $message->created_at?->format('d/m H:i') }}</span>
                                </p>
                            </div>
                        @else
                            @php $isStaff = $message->sender_type === 'user'; @endphp
                            <div wire:key="msg-{{ $message->id }}" @class([
                                'flex',
                                'justify-end' => $isStaff,
                                'justify-start' => ! $isStaff,
                            ])>
                                <div @class([
                                    'max-w-[85%] rounded px-3.5 py-2.5',
                                    'bg-primary-tint' => $isStaff,
                                    'bg-surface' => ! $isStaff,
                                ])>
                                    <p class="text-[11px] font-semibold text-muted">
                                        {{ $message->sender_name ?: ($isStaff ? '' : __('backoffice.support_requests.driver_sender')) }}
                                    </p>
                                    @if ($message->body !== null)
                                        <p class="mt-0.5 whitespace-pre-line text-[13px] text-ink">{{ $message->body }}</p>
                                    @endif
                                    @foreach ($message->attachments as $attachment)
                                        <p wire:key="att-{{ $attachment->id }}" class="mt-1 text-[11px] font-medium text-muted">
                                            {{ __('backoffice.support_requests.attachment') }} — {{ $attachment->original_name }}
                                        </p>
                                    @endforeach
                                    <p class="mt-1 text-[10.5px] text-muted">{{ $message->created_at?->format('d/m H:i') }}</p>
                                </div>
                            </div>
                        @endif
                    @empty
                        <p class="py-8 text-center text-sm text-muted">{{ __('backoffice.support_requests.thread_empty') }}</p>
                    @endforelse
                </div>

                <div class="border-t border-line px-5 py-4">
                    @if ($liveRequest === null)
                        <p class="text-xs text-muted">{{ __('backoffice.support_requests.no_live_request') }}</p>
                    @else
                        {{-- `.blur` et non `.live` : un aller-retour serveur par frappe
                             rendrait la saisie inutilisable. --}}
                        <label class="sr-only" for="draft">{{ __('backoffice.support_requests.compose_placeholder') }}</label>
                        <textarea wire:model.blur="draft" id="draft" rows="3"
                                  placeholder="{{ __('backoffice.support_requests.compose_placeholder') }}"
                                  class="block w-full rounded border border-input px-3 py-2 text-sm placeholder:text-muted focus:border-primary"></textarea>
                        @error('draft') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror

                        <div class="mt-2.5 flex items-center gap-2.5">
                            <button wire:click="$toggle('templatesOpen')"
                                    class="rounded border border-line bg-card px-3.5 py-2 text-xs font-semibold text-muted hover:bg-surface">
                                {{ __('backoffice.support_requests.templates') }}
                            </button>
                            <span class="flex-1"></span>
                            <button wire:click="send"
                                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                                {{ __('backoffice.support_requests.send') }}
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
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
                <button wire:click="cancelTicketForm" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.support_requests.cancel') }}
                </button>
                <button wire:click="createTicket" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.support_requests.create') }}
                </button>
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
                <button wire:click="cancelResolve" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.support_requests.cancel') }}
                </button>
                <button wire:click="resolve" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.support_requests.resolve') }}
                </button>
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
                <button wire:click="cancelDismiss" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                    {{ __('backoffice.support_requests.cancel') }}
                </button>
                <button wire:click="dismiss" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.support_requests.confirm') }}
                </button>
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
