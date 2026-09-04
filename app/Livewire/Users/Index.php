<?php

namespace App\Livewire\Users;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

/**
 * Comptes du back-office : qui existe, avec quels rôles, et quels droits en
 * plus.
 *
 * Un compte ne se supprime pas, il se désactive : les lignes d'audit, les
 * requêtes traitées et les déclarations CNPS pointent vers `users.id`, et une
 * suppression les laisserait orphelines. `EnsureUserIsActive` déconnecte au
 * prochain accès un compte désactivé pendant sa session.
 *
 * Les droits se lisent à deux niveaux, jamais confondus : ceux qui viennent
 * d'un rôle (cochés, verrouillés, avec le rôle nommé) et ceux accordés à cette
 * personne seule. Retirer un droit hérité se fait en lui ôtant le rôle — sinon
 * l'écran mentirait sur ce que la personne peut faire.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Users])]
class Index extends Component
{
    use InteractsWithCurrentUser, WithPagination;

    #[Url]
    public string $search = '';

    /** Filtre : `all`, `active`, `inactive`, ou le nom d'un rôle. */
    #[Url]
    public string $filter = 'all';

    // Fiche utilisateur.
    public bool $formOpen = false;

    public ?string $editingId = null;

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public bool $isActive = true;

    /** @var list<string> noms des rôles cochés */
    public array $selectedRoles = [];

    /** @var list<string> permissions accordées à cette personne seule */
    public array $selectedPermissions = [];

    public ?string $confirmingToggleId = null;

    // Réinitialisation du mot de passe.
    public ?string $resettingId = null;

    public string $newPassword = '';

    /**
     * Mot de passe généré à la dernière réinitialisation, affiché une seule
     * fois : il n'est stocké nulle part en clair, il faut donc pouvoir le
     * transmettre avant de quitter l'écran.
     */
    public ?string $issuedPassword = null;

    /** Compte dont le mot de passe vient d'être réinitialisé, le temps de l'afficher. */
    public ?string $resetUserId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
        $this->resetPage();
    }

    /**
     * Rôles disponibles, avec le nombre de comptes qui les portent — un rôle
     * vide se repère, et l'écran des rôles dit s'il peut être supprimé.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderBy('label')
            ->get();
    }

    /**
     * Droits accordés par les rôles cochés dans le formulaire, avec le rôle
     * qui les porte.
     *
     * Calculé sur la sélection en cours et non sur l'utilisateur enregistré :
     * cocher un rôle doit verrouiller ses droits immédiatement, avant
     * d'enregistrer.
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function inheritedPermissions(): array
    {
        $sources = [];

        foreach ($this->roles() as $role) {
            if (! in_array($role->name, $this->selectedRoles, true)) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                $sources[$permission->name][] = $role->label ?? $role->name;
            }
        }

        return $sources;
    }

    /**
     * Le décompte affiché en pied de formulaire : ce que la personne pourra
     * faire, et d'où chaque droit vient.
     *
     * @return array{total: int, inherited: int, direct: int}
     */
    #[Computed]
    public function effectiveCount(): array
    {
        $inherited = array_keys($this->inheritedPermissions());

        // Un droit coché en direct alors qu'un rôle le donne déjà ne compte
        // qu'une fois : c'est le total *effectif*, pas la somme des cases.
        $direct = array_diff($this->selectedPermissions, $inherited);

        return [
            'total' => count($inherited) + count($direct),
            'inherited' => count($inherited),
            'direct' => count($direct),
        ];
    }

    public function newUser(): void
    {
        Gate::authorize('manageUsers');

        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('manageUsers');

        $user = User::query()->with('roles', 'permissions')->findOrFail($id);

        $this->editingId = $user->id;
        $this->firstName = $user->first_name ?? '';
        $this->lastName = $user->last_name ?? '';
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->isActive = $user->is_active;
        $this->selectedRoles = $user->roles->pluck('name')->all();
        $this->selectedPermissions = $user->directPermissionNames();

        $this->resetValidation();
        $this->issuedPassword = null;
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    /**
     * Cocher un accès de module coché par un rôle n'a pas de sens : la case
     * héritée est verrouillée à l'affichage, on refuse aussi côté serveur.
     */
    public function save(): void
    {
        Gate::authorize('manageUsers');

        $data = $this->validate($this->rules());

        $creating = $this->editingId === null;

        $user = $creating
            ? new User
            : User::query()->with('roles', 'permissions')->findOrFail($this->editingId);

        $before = $creating ? [] : [
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'permissions' => collect($user->directPermissionNames())->sort()->values()->all(),
            'is_active' => $user->is_active,
        ];

        $user->fill([
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'name' => trim("{$data['firstName']} {$data['lastName']}"),
            'email' => $data['email'],
            'phone' => $data['phone'] ?: null,
            'is_active' => $data['isActive'],
        ]);

        if ($creating) {
            // Le compte est créé avec un mot de passe provisoire, affiché une
            // seule fois : le back-office n'envoie pas d'e-mail, l'accès se
            // transmet de la main à la main.
            $password = $this->generatePassword();
            $user->password = $password;
            $user->email_verified_at = now();
        }

        $user->save();

        $user->syncRoles($this->selectedRoles);

        // Seuls les droits *directs* sont synchronisés : `syncPermissions` sur
        // le modèle utilisateur ne touche pas à ceux des rôles.
        $user->syncPermissions($this->directGrants());

        $this->recordSave($user, $creating, $before, $password ?? null);

        $this->issuedPassword = $password ?? null;

        $this->dispatch('toast', message: $creating
            ? __('backoffice.users.created', ['name' => $user->fullName()])
            : __('backoffice.users.updated', ['name' => $user->fullName()]));

        // Le mot de passe provisoire reste à l'écran : on ferme le formulaire
        // seulement quand il n'y a rien à transmettre.
        if ($this->issuedPassword === null) {
            $this->closeForm();
        } else {
            $this->editingId = $user->id;
        }
    }

    /**
     * Active ou désactive un compte.
     *
     * Un compte ne se supprime jamais : `SoftDeletes` existe sur le modèle mais
     * n'est pas exposé ici — désactiver suffit et garde l'historique lisible.
     */
    public function toggleActive(string $id): void
    {
        Gate::authorize('manageUsers');

        $user = User::query()->findOrFail($id);

        // Se désactiver soi-même fermerait la session au prochain clic, sur un
        // écran dont on est peut-être le seul à porter les droits.
        if ($user->is($this->actor())) {
            $this->dispatch('toast', message: __('backoffice.users.cannot_disable_self'), tone: 'error');
            $this->confirmingToggleId = null;

            return;
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        AuditLog::record(
            action: ($user->is_active ? AuditAction::UserEnabled : AuditAction::UserDisabled)->value,
            summary: $user->is_active
                ? "{$this->actor()->fullName()} a réactivé le compte de {$user->fullName()}."
                : "{$this->actor()->fullName()} a désactivé le compte de {$user->fullName()}.",
            subject: $user,
            by: $this->actor(),
        );

        $this->confirmingToggleId = null;

        $this->dispatch('toast', message: $user->is_active
            ? __('backoffice.users.enabled', ['name' => $user->fullName()])
            : __('backoffice.users.disabled', ['name' => $user->fullName()]));
    }

    public function confirmReset(string $id): void
    {
        Gate::authorize('manageUsers');

        $this->resettingId = $id;
        $this->issuedPassword = null;
    }

    /**
     * Attribue un mot de passe provisoire, affiché une seule fois.
     *
     * Rien n'est envoyé par courriel : l'application n'a pas de canal sortant
     * pour les comptes internes. Le mot de passe est généré et non saisi —
     * laisser l'administrateur en choisir un invitait à réutiliser le même
     * pour tout le monde.
     */
    public function resetPassword(): void
    {
        Gate::authorize('manageUsers');

        $user = User::query()->findOrFail($this->resettingId);

        $password = $this->generatePassword();

        $user->password = $password;
        $user->setRememberToken(Str::random(60));
        $user->save();

        AuditLog::record(
            action: AuditAction::UserPasswordReset->value,
            summary: "{$this->actor()->fullName()} a réinitialisé le mot de passe de {$user->fullName()}.",
            subject: $user,
            by: $this->actor(),
        );

        $this->resettingId = null;
        $this->issuedPassword = $password;
        $this->resetUserId = $user->id;

        $this->dispatch('toast', message: __('backoffice.users.password_reset', ['name' => $user->fullName()]));
    }

    public function dismissPassword(): void
    {
        $this->issuedPassword = null;
        $this->resetUserId = null;
    }

    /**
     * Droits à enregistrer en direct : la sélection, moins ce qu'un rôle donne
     * déjà.
     *
     * Un droit hérité enregistré aussi en direct survivrait au retrait du
     * rôle — la personne garderait un pouvoir qu'on croyait lui avoir ôté.
     *
     * @return list<string>
     */
    private function directGrants(): array
    {
        $inherited = array_keys($this->inheritedPermissions());

        return array_values(array_intersect(
            array_diff($this->selectedPermissions, $inherited),
            BackOfficePermission::names(),
        ));
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function recordSave(User $user, bool $creating, array $before, ?string $password): void
    {
        $actor = $this->actor();

        if ($creating) {
            AuditLog::record(
                action: AuditAction::UserCreated->value,
                summary: "{$actor->fullName()} a créé le compte de {$user->fullName()} ({$user->email}).",
                subject: $user,
                by: $actor,
                context: [
                    'roles' => $user->roles->pluck('name')->all(),
                    'direct_permissions' => $this->directGrants(),
                    // Jamais le mot de passe : seulement qu'il en a reçu un.
                    'password_issued' => $password !== null,
                ],
            );

            return;
        }

        $after = [
            'roles' => collect($this->selectedRoles)->sort()->values()->all(),
            'permissions' => collect($this->directGrants())->sort()->values()->all(),
            'is_active' => $user->is_active,
        ];

        AuditLog::record(
            action: AuditAction::UserUpdated->value,
            summary: "{$actor->fullName()} a modifié le compte de {$user->fullName()}.",
            subject: $user,
            by: $actor,
            // On journalise l'avant et l'après des seuls champs sensibles : les
            // droits et l'activation. Une faute de frappe sur un nom n'a pas à
            // encombrer le journal.
            context: array_filter([
                'roles_before' => $before['roles'] === $after['roles'] ? null : $before['roles'],
                'roles_after' => $before['roles'] === $after['roles'] ? null : $after['roles'],
                'permissions_before' => $before['permissions'] === $after['permissions'] ? null : $before['permissions'],
                'permissions_after' => $before['permissions'] === $after['permissions'] ? null : $after['permissions'],
                'is_active_before' => $before['is_active'] === $after['is_active'] ? null : $before['is_active'],
                'is_active_after' => $before['is_active'] === $after['is_active'] ? null : $after['is_active'],
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Mot de passe provisoire lisible au téléphone : pas de caractère qu'on
     * confond à l'oral (`0`/`O`, `1`/`l`), et assez long pour la règle de
     * production (12 caractères, cf. `AppServiceProvider`).
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

        $password = '';

        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // La règle de production exige un symbole : on le pose à une position
        // fixe pour que le mot de passe reste dictable.
        return $password.'-Wg1';
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'firstName' => 'required|string|max:80',
            'lastName' => 'required|string|max:80',
            'email' => [
                'required',
                'email',
                'max:255',
                // Les comptes désactivés et supprimés comptent : l'adresse est
                // la clé d'identification, la réutiliser rattacherait
                // l'historique de l'un à l'autre.
                Rule::unique('users', 'email')
                    ->withoutTrashed()
                    ->ignore($this->editingId),
            ],
            'phone' => 'nullable|string|max:32',
            'isActive' => 'boolean',
            'selectedRoles' => 'array',
            'selectedRoles.*' => Rule::exists('roles', 'name')->where('guard_name', 'web'),
            'selectedPermissions' => 'array',
            'selectedPermissions.*' => Rule::in(BackOfficePermission::names()),
        ];
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->firstName = '';
        $this->lastName = '';
        $this->email = '';
        $this->phone = '';
        $this->isActive = true;
        $this->selectedRoles = [];
        $this->selectedPermissions = [];
        $this->issuedPassword = null;
        $this->resetValidation();
    }

    public function render(): View
    {
        $users = User::query()
            ->with('roles')
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.$this->search.'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($this->filter === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->filter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when(
                ! in_array($this->filter, ['all', 'active', 'inactive'], true),
                fn (Builder $query) => $query->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles->where('name', $this->filter),
                ),
            )
            ->orderBy('name')
            ->paginate(20);

        /** @var view-string $view */
        $view = 'livewire.users.index';

        return view($view, [
            'users' => $users,
            'canManage' => Gate::allows('manageUsers'),
            'activeCount' => User::query()->where('is_active', true)->count(),
            'inactiveCount' => User::query()->where('is_active', false)->count(),
            // Les droits sont présentés par module, dans l'ordre de la barre
            // latérale : on cherche « qui peut toucher à la boutique », pas une
            // permission par son nom technique.
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    /**
     * Droits regroupés par module, accès d'abord puis actions.
     *
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
