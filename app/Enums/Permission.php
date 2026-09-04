<?php

namespace App\Enums;

/**
 * Catalogue complet des droits du back-office.
 *
 * Une seule énumération, deux familles :
 *
 * - **Accès à un module** (`module.*`) — ouvre une entrée de la barre latérale
 *   et sa route. Dérivée de `BackOfficeModule` : le cas de l'énumération des
 *   modules reste la source, celui-ci n'en est que la façade côté droits.
 * - **Action sensible** — un geste que l'accès au module n'implique pas. Lire
 *   le catalogue n'est pas y écrire ; consulter le journal des recharges n'est
 *   pas rejouer un crédit.
 *
 * Ces droits étaient auparavant des `hasRole('direction')` dans
 * `AppServiceProvider` : un rôle nommé en dur, donc une décision d'organisation
 * figée dans le code. Les rôles s'administrant à l'écran, chaque geste porte
 * désormais sa permission, et les portails (`Gate`) la consultent.
 *
 * Toute permission ajoutée ici doit l'être aussi aux rôles déjà en base par une
 * migration : `RolePermissionSeeder` ne synchronise qu'à la création d'un rôle
 * (cf. `.ai/rules/vehicles.md`).
 */
enum Permission: string
{
    // Accès aux modules — un cas par `BackOfficeModule`.
    case ModuleDashboard = 'module.dashboard';
    case ModuleDrivers = 'module.drivers';
    case ModuleVehicles = 'module.vehicles';
    case ModuleSupportRequests = 'module.support-requests';
    case ModuleChallenges = 'module.challenges';
    case ModuleAnnouncements = 'module.announcements';
    case ModuleCampaigns = 'module.campaigns';
    case ModuleShop = 'module.shop';
    case ModuleShopOrders = 'module.shop-orders';
    case ModuleRecharges = 'module.recharges';
    case ModuleCnps = 'module.cnps';
    case ModuleUsers = 'module.users';
    case ModuleSettings = 'module.settings';
    case ModuleAudit = 'module.audit';

    // Actions sensibles.
    case ChallengesApproveSurprise = 'challenges.approve-surprise';
    case RechargesReconcile = 'recharges.reconcile';
    case ShopManageCatalogue = 'shop.manage-catalogue';
    case SupportReassign = 'support.reassign';
    case SettingsRevealSecrets = 'settings.reveal-secrets';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';

    /**
     * Module dont ce droit ouvre l'accès, ou `null` s'il s'agit d'une action.
     */
    public function module(): ?BackOfficeModule
    {
        return BackOfficeModule::tryFrom(
            (string) str($this->value)->after('module.'),
        );
    }

    /**
     * Le module auquel ce droit se rattache à l'écran des rôles.
     *
     * Une action est présentée sous le module qu'elle prolonge : « Gérer le
     * catalogue » se coche sous Boutique, à côté de l'accès qu'elle suppose.
     */
    public function belongsTo(): BackOfficeModule
    {
        return $this->module() ?? match ($this) {
            self::ChallengesApproveSurprise => BackOfficeModule::Challenges,
            self::RechargesReconcile => BackOfficeModule::Recharges,
            self::ShopManageCatalogue => BackOfficeModule::Shop,
            self::SupportReassign => BackOfficeModule::SupportRequests,
            self::SettingsRevealSecrets => BackOfficeModule::Settings,
            self::UsersManage, self::RolesManage => BackOfficeModule::Users,
        };
    }

    /**
     * Libellé de la case à cocher.
     *
     * Un accès au module reprend le nom du module ; une action dit le geste,
     * à l'infinitif, pour se distinguer de l'accès juste au-dessus.
     */
    public function label(): string
    {
        return $this->module()?->label() ?? match ($this) {
            self::ChallengesApproveSurprise => 'Approuver un bonus surprise',
            self::RechargesReconcile => 'Réconcilier et rejouer un crédit',
            self::ShopManageCatalogue => 'Gérer le catalogue',
            self::SupportReassign => 'Réattribuer une requête',
            self::SettingsRevealSecrets => 'Relever une clé en clair',
            self::UsersManage => 'Gérer les utilisateurs',
            self::RolesManage => 'Gérer les rôles',
        };
    }

    /**
     * Ce que le droit autorise, et ce qu'il n'autorise pas. Affiché sous la
     * case : la matrice des rôles est le seul endroit où l'on décide qui peut
     * quoi, elle doit se lire sans aller chercher le code.
     */
    public function hint(): ?string
    {
        return match ($this) {
            self::ChallengesApproveSurprise => "Un bonus surprise attribue un prix hors classement : l'approbation est le contrôle.",
            self::RechargesReconcile => "Touche à l'argent d'un conducteur. L'accès au module n'ouvre que la lecture du journal.",
            self::ShopManageCatalogue => 'Créer une référence, changer un prix, fermer une pièce à la commande, faire avancer une commande.',
            self::SupportReassign => "Désigner un autre destinataire qu'à soi-même : c'est répartir la charge de l'équipe.",
            self::SettingsRevealSecrets => 'Affiche en clair les clés Wave et Yango. Chaque relevé est journalisé.',
            self::UsersManage => 'Créer un compte, changer son identité, ses rôles, ses droits, le désactiver.',
            self::RolesManage => 'Créer un rôle, changer ses permissions, le supprimer.',
            default => null,
        };
    }

    /**
     * Un accès de module est le socle : le décocher retire aussi les actions
     * du même module, qui n'auraient plus d'écran où s'exercer.
     */
    public function isModuleAccess(): bool
    {
        return $this->module() !== null;
    }

    /**
     * @return list<self>
     */
    public static function actionsFor(BackOfficeModule $module): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission): bool => ! $permission->isModuleAccess() && $permission->belongsTo() === $module,
        ));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
