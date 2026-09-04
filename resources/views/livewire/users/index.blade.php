{{--
    Comptes du back-office. La liste dit qui existe et ce que chacun porte ;
    la fiche règle les rôles puis, au besoin, les droits en plus.

    L'action de module vit dans l'en-tête du layout et parle à la racine par
    évènement Alpine (cf. .ai/rules/components.md).
--}}
<div x-on:open-user-form.window="$wire.newUser()">
    <x-slot:actions>
        {{-- Lien et non bouton : c'est une navigation. `x-button` rend
             toujours un `<button>`, il n'accepte pas d'`href`. --}}
        <a href="{{ route('bo.users.roles') }}" wire:navigate
           class="inline-flex shrink-0 items-center justify-center gap-2 rounded border border-line bg-card px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-surface">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 12h6m-6 4h6m-6-8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/></svg>
            {{ __('backoffice.users.manage_roles') }}
        </a>
        @if ($canManage)
            <x-button x-on:click="$dispatch('open-user-form')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                {{ __('backoffice.users.new_user') }}
            </x-button>
        @endif
    </x-slot:actions>

    {{-- Mot de passe provisoire : affiché une seule fois, il n'est stocké
         nulle part en clair. Le bandeau reste jusqu'à ce qu'on le referme,
         pour laisser le temps de le transmettre. --}}
    @if ($issuedPassword !== null && ! $formOpen)
        <x-banner tone="warn" :title="__('backoffice.users.password_issued_title')" class="mb-5">
            <p>{{ __('backoffice.users.password_issued_hint') }}</p>
            <p class="mt-2 select-all font-mono text-base font-semibold tracking-wide text-ink">{{ $issuedPassword }}</p>
            <x-slot:actions>
                <x-button size="sm" variant="secondary" wire:click="dismissPassword">{{ __('backoffice.users.password_dismiss') }}</x-button>
            </x-slot:actions>
        </x-banner>
    @endif

    <x-toolbar>
        <x-field
            :label="__('backoffice.users.search')"
            name="search"
            type="search"
            label-hidden
            wire:model.live.debounce.300ms="search"
            :placeholder="__('backoffice.users.search_placeholder')"
            class="w-full sm:max-w-xs"
        />

        <x-slot:end>
            <div class="flex flex-wrap gap-1.5">
                <x-chip-filter wire:click="filterBy('all')" :active="$filter === 'all'">{{ __('backoffice.users.filter_all') }}</x-chip-filter>
                <x-chip-filter wire:click="filterBy('active')" :active="$filter === 'active'" :count="$activeCount">{{ __('backoffice.users.filter_active') }}</x-chip-filter>
                <x-chip-filter wire:click="filterBy('inactive')" :active="$filter === 'inactive'" :count="$inactiveCount" tone="danger">{{ __('backoffice.users.filter_inactive') }}</x-chip-filter>
                @foreach ($this->roles as $role)
                    <x-chip-filter wire:key="filter-{{ $role->id }}" wire:click="filterBy('{{ $role->name }}')" :active="$filter === $role->name" :count="$role->users_count">
                        {{ $role->label ?? $role->name }}
                    </x-chip-filter>
                @endforeach
            </div>
        </x-slot:end>
    </x-toolbar>

    <x-table class="mt-4" loading="search,filterBy,gotoPage,nextPage,previousPage">
        <x-slot:head>
            <tr>
                <x-th>{{ __('backoffice.users.col_user') }}</x-th>
                <x-th>{{ __('backoffice.users.col_roles') }}</x-th>
                <x-th align="center">{{ __('backoffice.users.col_extra') }}</x-th>
                <x-th>{{ __('backoffice.users.col_last_login') }}</x-th>
                <x-th align="center">{{ __('backoffice.users.col_state') }}</x-th>
                <x-th align="end">{{ __('backoffice.common.actions') }}</x-th>
            </tr>
        </x-slot:head>

        @foreach ($users as $row)
            <tr wire:key="user-{{ $row->id }}" class="transition-colors hover:bg-surface">
                <x-td>
                    <div class="flex items-center gap-2.5">
                        <x-avatar :initials="$row->initials()" alt="" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink">{{ $row->fullName() }}</p>
                            <p class="truncate text-xs text-muted">{{ $row->email }}</p>
                        </div>
                    </div>
                </x-td>

                <x-td>
                    @forelse ($row->roles as $role)
                        <x-badge wire:key="user-{{ $row->id }}-role-{{ $role->id }}" tone="neutral" class="mr-1">{{ $role->label ?? $role->name }}</x-badge>
                    @empty
                        {{-- Sans rôle, la barre latérale est vide : la personne
                             se connecte et ne voit rien. C'est une anomalie,
                             pas un état. --}}
                        <span class="text-xs text-err-text">{{ __('backoffice.users.no_role') }}</span>
                    @endforelse
                </x-td>

                <x-td align="center">
                    @php $extra = count($row->directPermissionNames()); @endphp
                    @if ($extra > 0)
                        <x-badge tone="primary">{{ __('backoffice.users.extra_count', ['count' => $extra]) }}</x-badge>
                    @else
                        <span class="text-xs text-muted">—</span>
                    @endif
                </x-td>

                <x-td muted nowrap>
                    {{ $row->last_login_at?->translatedFormat('j M Y H:i') ?? __('backoffice.users.never_logged_in') }}
                </x-td>

                <x-td align="center">
                    <x-badge :tone="$row->is_active ? 'ok' : 'err'">
                        {{ $row->is_active ? __('backoffice.users.state_active') : __('backoffice.users.state_inactive') }}
                    </x-badge>
                </x-td>

                <x-td align="end" nowrap>
                    @if ($canManage)
                        <div class="flex justify-end gap-1.5">
                            <x-button size="sm" variant="secondary" wire:click="edit('{{ $row->id }}')" target="edit">
                                {{ __('backoffice.common.edit') }}
                            </x-button>
                            <x-button size="sm" variant="secondary" wire:click="confirmReset('{{ $row->id }}')">
                                {{ __('backoffice.users.reset_password') }}
                            </x-button>
                            <x-button size="sm" variant="danger-outline" wire:click="$set('confirmingToggleId', '{{ $row->id }}')">
                                {{ $row->is_active ? __('backoffice.users.disable') : __('backoffice.users.enable') }}
                            </x-button>
                        </div>
                    @else
                        <span class="text-xs text-muted">—</span>
                    @endif
                </x-td>
            </tr>
        @endforeach

        @if ($users->isEmpty())
            <x-slot:empty>
                <x-empty-state :title="__('backoffice.users.empty_title')" :hint="__('backoffice.users.empty_hint')" tone="neutral">
                    <x-slot:action>
                        <x-button variant="secondary" size="sm" wire:click="resetFilters" target="resetFilters">{{ __('backoffice.common.reset_filters') }}</x-button>
                    </x-slot:action>
                </x-empty-state>
            </x-slot:empty>
        @endif

        <x-slot:footer>{{ $users->links() }}</x-slot:footer>
    </x-table>

    {{-- Fiche utilisateur : identité, rôles, puis droits en plus. L'ordre est
         celui de la décision — on donne un rôle, et on n'ouvre la matrice que
         si ce rôle ne suffit pas. --}}
    @if ($formOpen)
        <x-modal close="closeForm" size="xl" align="start"
                 :title="$editingId === null ? __('backoffice.users.form_new_title') : __('backoffice.users.form_edit_title')"
                 :description="__('backoffice.users.form_hint')">
            <form id="user-form" wire:submit="save" class="grid gap-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('backoffice.users.first_name')" name="firstName" wire:model="firstName" required />
                    <x-field :label="__('backoffice.users.last_name')" name="lastName" wire:model="lastName" required />
                    <x-field :label="__('backoffice.users.email')" name="email" type="email" wire:model="email" autocomplete="off" required />
                    <x-field :label="__('backoffice.users.phone')" name="phone" wire:model="phone" placeholder="+225…" />
                </div>

                <label class="flex items-center gap-2 text-sm text-ink">
                    <input id="user-is-active" type="checkbox" wire:model="isActive" class="size-4 rounded border-input text-primary">
                    <span>{{ __('backoffice.users.is_active_label') }}</span>
                </label>

                {{-- Rôles : le geste courant. Un rôle est un jeu de droits
                     nommé, il se relit d'un coup d'œil. --}}
                <fieldset>
                    <legend class="mb-2 text-xs font-semibold text-muted">{{ __('backoffice.users.roles_legend') }}</legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->roles as $role)
                            <label wire:key="pick-role-{{ $role->id }}"
                                   class="flex cursor-pointer items-start gap-2.5 rounded border border-line bg-card p-3 transition-colors hover:bg-surface">
                                <input type="checkbox" value="{{ $role->name }}" wire:model.live="selectedRoles"
                                       class="mt-0.5 size-4 rounded border-input text-primary">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-ink">{{ $role->label ?? $role->name }}</span>
                                    @if ($role->description)
                                        <span class="block text-xs text-muted">{{ $role->description }}</span>
                                    @endif
                                    <span class="mt-1 block text-[11px] text-muted">{{ trans_choice('backoffice.users.role_permission_count', $role->permissions->count(), ['count' => $role->permissions->count()]) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('selectedRoles.*')<p class="mt-1.5 text-xs text-err-text">{{ $message }}</p>@enderror
                </fieldset>

                {{-- Droits en plus. Une case cochée par un rôle est verrouillée
                     et dit lequel : sinon on la décocherait sans effet, le rôle
                     la rendant aussitôt. Pour la retirer, ôter le rôle. --}}
                <fieldset x-data="{ open: false }">
                    <legend class="sr-only">{{ __('backoffice.users.permissions_legend') }}</legend>

                    <button type="button" x-on:click="open = ! open"
                            class="flex w-full items-center justify-between rounded border border-line bg-surface px-3 py-2.5 text-left transition-colors hover:bg-card">
                        <span>
                            <span class="block text-sm font-medium text-ink">{{ __('backoffice.users.permissions_legend') }}</span>
                            <span class="block text-xs text-muted">
                                {{ trans_choice('backoffice.users.effective_summary', $this->effectiveCount['total'], [
                                    'total' => $this->effectiveCount['total'],
                                    'inherited' => $this->effectiveCount['inherited'],
                                    'direct' => $this->effectiveCount['direct'],
                                ]) }}
                            </span>
                        </span>
                        <svg class="size-4 shrink-0 text-muted transition-transform" x-bind:class="open && 'rotate-180'"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="open" x-cloak class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach ($permissionGroups as $group)
                            <div wire:key="perm-group-{{ $group['module']->value }}" class="rounded border border-line bg-card p-3">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $group['module']->label() }}</p>

                                <div class="grid gap-1.5">
                                    @foreach ($group['permissions'] as $permission)
                                        @php
                                            $sources = $this->inheritedPermissions[$permission->value] ?? [];
                                            $inherited = $sources !== [];
                                        @endphp
                                        <label wire:key="perm-{{ $permission->value }}"
                                               @class([
                                                   'flex items-start gap-2 rounded px-1.5 py-1 text-sm',
                                                   'cursor-pointer hover:bg-surface' => ! $inherited,
                                                   'cursor-not-allowed opacity-90' => $inherited,
                                               ])>
                                            <input type="checkbox"
                                                   class="mt-0.5 size-4 shrink-0 rounded border-input text-primary disabled:opacity-100"
                                                   @if ($inherited) checked disabled @else value="{{ $permission->value }}" wire:model="selectedPermissions" @endif>
                                            <span class="min-w-0">
                                                <span class="block text-ink">{{ $permission->label() }}</span>
                                                @if ($inherited)
                                                    <span class="block text-[11px] text-primary-text">{{ __('backoffice.users.via_role', ['roles' => implode(', ', array_unique($sources))]) }}</span>
                                                @elseif ($permission->hint())
                                                    <span class="block text-[11px] text-muted">{{ $permission->hint() }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                @if ($issuedPassword !== null)
                    <div class="rounded border border-warn-text/40 bg-warn-bg p-3">
                        <p class="text-xs font-semibold text-warn-text">{{ __('backoffice.users.password_issued_title') }}</p>
                        <p class="mt-1 text-xs text-warn-text">{{ __('backoffice.users.password_issued_hint') }}</p>
                        <p class="mt-2 select-all font-mono text-base font-semibold tracking-wide text-ink">{{ $issuedPassword }}</p>
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.common.cancel') }}</x-button>
                    <x-button type="submit" form="user-form" target="save">
                        {{ __('backoffice.settings.save') }}
                        <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                    </x-button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- Désactivation : le compte reste, il ne se connecte plus. --}}
    @if ($confirmingToggleId !== null)
        @php $target = $users->firstWhere('id', $confirmingToggleId); @endphp
        <x-confirm
            close="$set('confirmingToggleId', null)"
            action="toggleActive('{{ $confirmingToggleId }}')"
            :title="$target?->is_active ? __('backoffice.users.disable_title') : __('backoffice.users.enable_title')"
            :body="$target?->is_active
                ? __('backoffice.users.disable_body', ['name' => $target->fullName()])
                : __('backoffice.users.enable_body', ['name' => $target?->fullName()])"
            :confirm-label="$target?->is_active ? __('backoffice.users.disable') : __('backoffice.users.enable')"
            :variant="$target?->is_active ? 'danger' : 'primary'"
            loading="toggleActive"
        />
    @endif

    {{-- Réinitialisation : le mot de passe est généré, jamais choisi — laisser
         l'administrateur en saisir un invitait à réutiliser le même partout. --}}
    @if ($resettingId !== null)
        @php $target = $users->firstWhere('id', $resettingId); @endphp
        <x-confirm
            close="$set('resettingId', null)"
            action="resetPassword"
            :title="__('backoffice.users.reset_title')"
            :body="__('backoffice.users.reset_body', ['name' => $target?->fullName() ?? ''])"
            :confirm-label="__('backoffice.users.reset_confirm')"
            variant="danger"
            loading="resetPassword"
        />
    @endif
</div>
