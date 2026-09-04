<?php

namespace App\Livewire\Users;

use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles et matrice des droits : un rôle est un jeu de permissions nommé.
 *
 * Les rôles sont administrés en base et non figés dans une énumération : une
 * réorganisation de l'équipe ne doit pas demander un déploiement. Les
 * *permissions*, elles, suivent le code (`Permission`) — elles nomment ce que
 * l'application sait faire, ce qui n'est pas une décision d'organisation.
 *
 * L'accès à un module est le socle : le décocher retire les actions du même
 * module, qui n'auraient plus d'écran où s'exercer.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Users])]
class Roles extends Component
{
    public ?string $editingId = null;

    public bool $formOpen = false;

    public string $label = '';

    public string $description = '';

    /** @var list<string> */
    public array $selectedPermissions = [];

    public ?string $confirmingDeleteId = null;

    public function newRole(): void
    {
        Gate::authorize('manageRoles');

        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('manageRoles');

        $role = Role::query()->with('permissions')->findOrFail($id);

        $this->editingId = $role->id;
        $this->label = $role->label ?? $role->name;
        $this->description = $role->description ?? '';
        $this->selectedPermissions = $role->permissions->pluck('name')->all();

        $this->resetValidation();
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    /**
     * Coche ou décoche un droit.
     *
     * Décocher l'accès à un module emporte ses actions : garder « Gérer le
     * catalogue » sans « Produits » laissait une case cochée sans effet, donc
     * un écran qui mentait. Cocher une action donne l'accès au module, pour la
     * même raison.
     */
    public function togglePermission(string $name): void
    {
        $permission = BackOfficePermission::tryFrom($name);

        if ($permission === null) {
            return;
        }

        $selected = collect($this->selectedPermissions);

        if ($selected->contains($name)) {
            $selected = $selected->reject(fn (string $held): bool => $held === $name);

            if ($permission->isModuleAccess()) {
                $actions = collect(BackOfficePermission::actionsFor($permission->module()))
                    ->map(fn (BackOfficePermission $action): string => $action->value);

                $selected = $selected->reject(fn (string $held): bool => $actions->contains($held));
            }
        } else {
            $selected = $selected->push($name);

            if (! $permission->isModuleAccess()) {
                $selected = $selected->push($permission->belongsTo()->permission());
            }
        }

        $this->selectedPermissions = $selected->unique()->values()->all();
    }

    public function save(): void
    {
        Gate::authorize('manageRoles');

        $data = $this->validate([
            'label' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'selectedPermissions' => 'array',
            'selectedPermissions.*' => Rule::in(BackOfficePermission::names()),
        ]);

        $creating = $this->editingId === null;

        $role = $creating
            ? new Role(['guard_name' => 'web'])
            : Role::query()->with('permissions')->findOrFail($this->editingId);

        $before = $creating
            ? []
            : $role->permissions->pluck('name')->sort()->values()->all();

        if ($creating) {
            // Le nom technique est dérivé du libellé une seule fois, à la
            // création : il sert de clé aux permissions déjà accordées et aux
            // rôles cités dans le code, renommer le libellé ne doit pas le
            // changer.
            $role->name = $this->uniqueName($this->label);
        }

        $role->label = $data['label'];
        $role->description = $data['description'] ?: null;
        $role->save();

        $role->syncPermissions($this->selectedPermissions);

        // La matrice change ce que chacun peut faire : le cache de spatie doit
        // repartir, sinon la session en cours garde ses anciens droits.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = collect($this->selectedPermissions)->sort()->values()->all();

        AuditLog::record(
            action: $creating ? 'role.created' : 'role.updated',
            summary: $creating
                ? "{$this->currentUser()->fullName()} a créé le rôle « {$role->label} »."
                : "{$this->currentUser()->fullName()} a modifié les droits du rôle « {$role->label} ».",
            subject: $role,
            by: $this->currentUser(),
            context: array_filter([
                'role' => $role->name,
                'permissions_before' => $creating || $before === $after ? null : $before,
                'permissions_after' => $creating ? $after : ($before === $after ? null : $after),
            ], fn (mixed $value): bool => $value !== null),
        );

        $this->closeForm();

        $this->dispatch('toast', message: $creating
            ? __('backoffice.users.role_created', ['label' => $role->label])
            : __('backoffice.users.role_updated', ['label' => $role->label]));
    }

    /**
     * Supprime un rôle, à condition que personne ne le porte.
     *
     * Un rôle supprimé sous les pieds de ses titulaires les priverait de leurs
     * droits sans trace de la raison : on demande de les réaffecter d'abord.
     */
    public function delete(string $id): void
    {
        Gate::authorize('manageRoles');

        $role = Role::query()->withCount('users')->findOrFail($id);

        if ($role->users_count > 0) {
            $this->confirmingDeleteId = null;

            $this->dispatch(
                'toast',
                message: trans_choice('backoffice.users.role_in_use', $role->users_count, ['count' => $role->users_count]),
                tone: 'error',
            );

            return;
        }

        $label = $role->label ?? $role->name;

        AuditLog::record(
            action: 'role.deleted',
            summary: "{$this->currentUser()->fullName()} a supprimé le rôle « {$label} ».",
            by: $this->currentUser(),
            context: ['role' => $role->name],
        );

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->confirmingDeleteId = null;

        $this->dispatch('toast', message: __('backoffice.users.role_deleted', ['label' => $label]));
    }

    /**
     * Nom technique libre, dérivé du libellé. Un suffixe numérique tranche les
     * homonymes plutôt que d'écraser un rôle existant.
     */
    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $suffix = 2;

        while (Role::query()->where('guard_name', 'web')->where('name', $name)->exists()) {
            $name = "{$base}-{$suffix}";
            $suffix++;
        }

        return $name;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->label = '';
        $this->description = '';
        $this->selectedPermissions = [];
        $this->resetValidation();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        /** @var Collection<int, Role> $roles */
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderBy('label')
            ->get();

        /** @var view-string $view */
        $view = 'livewire.users.roles';

        return view($view, [
            'roles' => $roles,
            'canManage' => Gate::allows('manageRoles'),
            'permissionGroups' => $this->permissionGroups(),
            'totalPermissions' => count(BackOfficePermission::cases()),
        ]);
    }

    /**
     * @return list<array{module: BackOfficeModule, permissions: list<BackOfficePermission>}>
     */
    private function permissionGroups(): array
    {
        return array_values(array_map(
            fn (BackOfficeModule $module): array => [
                'module' => $module,
                'permissions' => [
                    $module->permissionEnum(),
                    ...BackOfficePermission::actionsFor($module),
                ],
            ],
            BackOfficeModule::cases(),
        ));
    }
}
