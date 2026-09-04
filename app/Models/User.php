<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BackOfficeModule;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * Utilisateur du back-office (guard `web`, session).
 *
 * Le MCD prévoit une entité dédiée aux opérateurs ; le projet la porte par
 * `users` — il n'existe donc pas de table séparée, et tout ce qui référence un
 * opérateur pointe vers `users.id`.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'first_name', 'last_name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Utilisateurs pouvant se connecter.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Nom affiché : prénom + nom si renseignés, sinon `name`.
     */
    public function fullName(): string
    {
        $composed = trim("{$this->first_name} {$this->last_name}");

        return $composed !== '' ? $composed : $this->name;
    }

    /**
     * Libellé du rôle principal, tel qu'affiché dans la barre supérieure. Les
     * rôles étant administrés en base, on lit leur libellé plutôt qu'une
     * correspondance codée en dur.
     */
    public function roleLabel(): ?string
    {
        $name = $this->getRoleNames()->first();

        if ($name === null) {
            return null;
        }

        return Role::where('name', $name)->value('label') ?? $name;
    }

    /**
     * Modules visibles dans la barre latérale, dans l'ordre de l'énumération.
     *
     * Les permissions sont résolues en une fois (spatie les met en cache) : on
     * évite un appel d'autorisation par module.
     *
     * @return list<BackOfficeModule>
     */
    public function visibleModules(): array
    {
        // `loadMissing` plutôt que `getAllPermissions()` seul : ce dernier
        // parcourt les relations et déclencherait un chargement paresseux, que
        // `Model::shouldBeStrict()` interdit hors production.
        $this->loadMissing('roles.permissions', 'permissions');

        $granted = $this->getAllPermissions()->pluck('name');

        return array_values(array_filter(
            BackOfficeModule::cases(),
            fn (BackOfficeModule $module): bool => $granted->contains($module->permission()),
        ));
    }

    /**
     * Droits accordés par les rôles, avec le rôle qui les porte.
     *
     * L'écran des droits doit pouvoir dire *pourquoi* une case est cochée :
     * une permission héritée d'un rôle ne se retire pas au niveau de
     * l'utilisateur, seulement en lui ôtant le rôle. Sans cette distinction,
     * une case cochée-verrouillée serait inexplicable.
     *
     * @return array<string, list<string>> permission => libellés des rôles
     */
    public function permissionsByRole(): array
    {
        $this->loadMissing('roles.permissions');

        $sources = [];

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $sources[$permission->name][] = $role->label ?? $role->name;
            }
        }

        return $sources;
    }

    /**
     * Droits attachés à l'utilisateur seul, hors rôles.
     *
     * @return list<string>
     */
    public function directPermissionNames(): array
    {
        $this->loadMissing('permissions');

        return $this->permissions->pluck('name')->all();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
