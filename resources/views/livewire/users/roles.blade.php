{{--
    Rôles et matrice des droits. Un rôle est un jeu de permissions nommé : on
    le lit ici en entier, module par module, plutôt que d'aller chercher dans
    le code qui peut quoi.

    L'accès à un module est le socle de sa colonne : le décocher retire ses
    actions, qui n'auraient plus d'écran où s'exercer.
--}}
<div x-on:open-role-form.window="$wire.newRole()">
    <x-slot:back>
        <x-back-link href="{{ route('bo.users') }}">{{ __('backoffice.users.back_to_users') }}</x-back-link>
    </x-slot:back>

    <x-slot:actions>
        @if ($canManage)
            <x-button x-on:click="$dispatch('open-role-form')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                {{ __('backoffice.users.new_role') }}
            </x-button>
        @endif
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            <x-panel wire:key="role-{{ $role->id }}" :title="$role->label ?? $role->name" :subtitle="$role->description">
                <x-slot:actions>
                    @if ($canManage)
                        <x-button size="sm" variant="secondary" wire:click="edit('{{ $role->id }}')" target="edit">
                            {{ __('backoffice.common.edit') }}
                        </x-button>
                        {{-- Supprimer n'est proposé que si personne ne le
                             porte : autrement l'action échoue, et un bouton qui
                             échoue toujours n'a rien à faire là. --}}
                        @if ($role->users_count === 0)
                            <x-button size="sm" variant="danger-outline" wire:click="$set('confirmingDeleteId', '{{ $role->id }}')">
                                {{ __('backoffice.common.delete') }}
                            </x-button>
                        @endif
                    @endif
                </x-slot:actions>

                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :tone="$role->users_count > 0 ? 'neutral' : 'warn'">
                        {{ trans_choice('backoffice.users.role_user_count', $role->users_count, ['count' => $role->users_count]) }}
                    </x-badge>
                    <x-badge tone="primary">
                        {{ trans_choice('backoffice.users.role_permission_count', $role->permissions->count(), ['count' => $role->permissions->count()]) }}
                    </x-badge>
                    <span class="text-xs text-muted">{{ __('backoffice.users.role_of_total', ['total' => $totalPermissions]) }}</span>
                </div>

                @php
                    // Barre de couverture : la part des droits que ce rôle
                    // porte. La classe est résolue ici en entier — Tailwind ne
                    // génère pas une classe interpolée (cf. .ai/rules/views.md).
                    $share = $totalPermissions > 0 ? (int) round($role->permissions->count() / $totalPermissions * 100) : 0;
                    $bar = $share >= 90 ? 'bg-err-text' : ($share >= 50 ? 'bg-warn-text' : 'bg-primary');
                @endphp
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface" role="presentation">
                    <div class="h-full {{ $bar }}" style="width: {{ $share }}%"></div>
                </div>

                <ul class="mt-3 grid gap-1">
                    @foreach ($permissionGroups as $group)
                        @php
                            $held = collect($group['permissions'])
                                ->filter(fn ($permission) => $role->permissions->contains('name', $permission->value));
                        @endphp
                        @if ($held->isNotEmpty())
                            <li wire:key="role-{{ $role->id }}-{{ $group['module']->value }}" class="flex items-baseline gap-2 text-xs">
                                <span class="shrink-0 font-medium text-ink">{{ $group['module']->label() }}</span>
                                <span class="text-muted">
                                    {{-- L'accès seul ne s'écrit pas : le nom du
                                         module le dit déjà. On ne cite que les
                                         actions, qui sont l'information. --}}
                                    @php
                                        $actions = $held->reject(fn ($permission) => $permission->isModuleAccess())
                                            ->map(fn ($permission) => $permission->label());
                                    @endphp
                                    {{ $actions->isEmpty() ? __('backoffice.users.access_only') : $actions->implode(', ') }}
                                </span>
                            </li>
                        @endif
                    @endforeach
                </ul>

                @if ($role->permissions->isEmpty())
                    <p class="mt-3 text-xs text-err-text">{{ __('backoffice.users.role_no_permission') }}</p>
                @endif
            </x-panel>
        @endforeach
    </div>

    {{-- Éditeur : le libellé, puis la matrice complète. Le nom technique n'est
         pas modifiable — il sert de clé aux droits déjà accordés. --}}
    @if ($formOpen)
        <x-modal close="closeForm" size="xl" align="start"
                 :title="$editingId === null ? __('backoffice.users.role_form_new_title') : __('backoffice.users.role_form_edit_title')"
                 :description="__('backoffice.users.role_form_hint')">
            <form id="role-form" wire:submit="save" class="grid gap-5">
                <div class="grid gap-4">
                    <x-field :label="__('backoffice.users.role_label')" name="label" wire:model="label" required />
                    <x-field :label="__('backoffice.users.role_description')" name="description" type="textarea" rows="2" wire:model="description" />
                </div>

                <fieldset>
                    <legend class="mb-2 text-xs font-semibold text-muted">
                        {{ trans_choice('backoffice.users.role_permissions_legend', count($selectedPermissions), ['count' => count($selectedPermissions)]) }}
                    </legend>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($permissionGroups as $group)
                            @php
                                $moduleAccess = $group['module']->permission();
                                $hasAccess = in_array($moduleAccess, $selectedPermissions, true);
                            @endphp
                            <div wire:key="role-perm-{{ $group['module']->value }}" class="rounded border border-line bg-card p-3">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $group['module']->label() }}</p>

                                <div class="grid gap-1.5">
                                    @foreach ($group['permissions'] as $permission)
                                        @php $checked = in_array($permission->value, $selectedPermissions, true); @endphp
                                        <label wire:key="role-perm-{{ $permission->value }}"
                                               class="flex cursor-pointer items-start gap-2 rounded px-1.5 py-1 text-sm hover:bg-surface">
                                            {{-- `togglePermission` et non `wire:model` :
                                                 cocher une action doit entraîner l'accès
                                                 au module, et décocher l'accès emporter
                                                 les actions. --}}
                                            <input type="checkbox"
                                                   wire:click="togglePermission('{{ $permission->value }}')"
                                                   @checked($checked)
                                                   class="mt-0.5 size-4 shrink-0 rounded border-input text-primary">
                                            <span class="min-w-0">
                                                <span @class(['block', 'font-medium text-ink' => $permission->isModuleAccess(), 'text-ink' => ! $permission->isModuleAccess()])>
                                                    {{ $permission->label() }}
                                                </span>
                                                @if ($permission->hint())
                                                    <span class="block text-[11px] text-muted">{{ $permission->hint() }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                @if (! $hasAccess && count($group['permissions']) > 1)
                                    <p class="mt-1.5 text-[11px] text-muted">{{ __('backoffice.users.actions_need_access') }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('selectedPermissions.*')<p class="mt-1.5 text-xs text-err-text">{{ $message }}</p>@enderror
                </fieldset>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.common.cancel') }}</x-button>
                    <x-button type="submit" form="role-form" target="save">
                        {{ __('backoffice.settings.save') }}
                        <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                    </x-button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($confirmingDeleteId !== null)
        @php $target = $roles->firstWhere('id', $confirmingDeleteId); @endphp
        <x-confirm
            close="$set('confirmingDeleteId', null)"
            action="delete('{{ $confirmingDeleteId }}')"
            :title="__('backoffice.users.role_delete_title')"
            :body="__('backoffice.users.role_delete_body', ['label' => $target?->label ?? $target?->name ?? ''])"
            :confirm-label="__('backoffice.common.delete')"
            variant="danger"
            loading="delete"
        />
    @endif
</div>
