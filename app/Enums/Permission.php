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

    /*
    | Actions sensibles.
    |
    | Une par décision distincte, groupées par module. L'accès au module ouvre
    | la lecture ; chacune de ces permissions ouvre un geste que la lecture
    | n'implique pas. Le découpage suit les conséquences, pas les verbes CRUD :
    | répondre à un ticket et l'écarter sans réponse ne s'accordent pas
    | ensemble, exécuter un tirage et créditer ses lots non plus.
    */

    // Chauffeurs — suspendre coupe le revenu d'un conducteur.
    case DriversSuspend = 'drivers.suspend';

    // Requêtes.
    case SupportHandle = 'support.handle';
    case SupportDismiss = 'support.dismiss';
    case SupportReassign = 'support.reassign';
    case SupportManageTemplates = 'support.manage-templates';

    // Challenges — le cycle de vie d'une gratification, geste par geste.
    case ChallengesCreate = 'challenges.create';
    case ChallengesApproveSurprise = 'challenges.approve-surprise';
    case ChallengesClosePeriod = 'challenges.close-period';
    case ChallengesDraw = 'challenges.draw';
    case ChallengesRegenerateSeed = 'challenges.regenerate-seed';
    case ChallengesCredit = 'challenges.credit';
    case ChallengesManagePrizes = 'challenges.manage-prizes';

    // Annonces.
    case AnnouncementsManage = 'announcements.manage';
    case AnnouncementsPublish = 'announcements.publish';

    // Campagnes — écrire un brouillon n'est pas le diffuser.
    case CampaignsManage = 'campaigns.manage';
    case CampaignsSend = 'campaigns.send';

    // Boutique.
    case ShopManageCatalogue = 'shop.manage-catalogue';
    case ShopFulfilOrders = 'shop.fulfil-orders';
    case ShopCancelOrder = 'shop.cancel-order';

    // Finance.
    case RechargesReconcile = 'recharges.reconcile';

    /*
    | Système.
    |
    | `settings.manage` enregistre les réglages — y compris les clés Wave et
    | Yango. Écraser un secret d'encaissement est au moins aussi grave que le
    | lire : `settings.reveal-secrets` ne gardait que la lecture.
    */
    case SettingsManage = 'settings.manage';
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
            self::DriversSuspend => BackOfficeModule::Drivers,

            self::SupportHandle,
            self::SupportDismiss,
            self::SupportReassign,
            self::SupportManageTemplates => BackOfficeModule::SupportRequests,

            self::ChallengesCreate,
            self::ChallengesApproveSurprise,
            self::ChallengesClosePeriod,
            self::ChallengesDraw,
            self::ChallengesRegenerateSeed,
            self::ChallengesCredit,
            self::ChallengesManagePrizes => BackOfficeModule::Challenges,

            self::AnnouncementsManage,
            self::AnnouncementsPublish => BackOfficeModule::Announcements,

            self::CampaignsManage,
            self::CampaignsSend => BackOfficeModule::Campaigns,

            self::ShopManageCatalogue => BackOfficeModule::Shop,

            // Les commandes ont leur propre module : la préparation et
            // l'annulation s'y cochent, pas sous le catalogue.
            self::ShopFulfilOrders,
            self::ShopCancelOrder => BackOfficeModule::ShopOrders,

            self::RechargesReconcile => BackOfficeModule::Recharges,

            self::SettingsManage,
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
            self::DriversSuspend => 'Suspendre ou réactiver un conducteur',

            self::SupportHandle => 'Traiter une requête',
            self::SupportDismiss => 'Écarter un message sans réponse',
            self::SupportReassign => 'Réattribuer une requête',
            self::SupportManageTemplates => 'Gérer les réponses types',

            self::ChallengesCreate => 'Créer un challenge',
            self::ChallengesApproveSurprise => 'Approuver un bonus surprise',
            self::ChallengesClosePeriod => 'Clore la période',
            self::ChallengesDraw => 'Exécuter le tirage',
            self::ChallengesRegenerateSeed => 'Republier la graine du tirage',
            self::ChallengesCredit => 'Marquer un lot crédité',
            self::ChallengesManagePrizes => 'Gérer le catalogue des lots',

            self::AnnouncementsManage => 'Gérer les annonces',
            self::AnnouncementsPublish => 'Publier ou retirer une annonce',

            self::CampaignsManage => 'Rédiger une campagne',
            self::CampaignsSend => 'Envoyer une campagne',

            self::ShopManageCatalogue => 'Gérer le catalogue',
            self::ShopFulfilOrders => 'Faire avancer une commande',
            self::ShopCancelOrder => 'Annuler une commande',

            self::RechargesReconcile => 'Réconcilier et rejouer un crédit',

            self::SettingsManage => 'Enregistrer les réglages et les clés',
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
            self::DriversSuspend => 'Une suspension coupe le revenu du conducteur : il ne reçoit plus de courses.',

            self::SupportHandle => 'Répondre dans un fil, changer la catégorie, résoudre un ticket.',
            self::SupportDismiss => "Classer un message sans y répondre : le conducteur n'obtient aucune réponse.",
            self::SupportReassign => "Désigner un autre destinataire qu'à soi-même : c'est répartir la charge de l'équipe.",
            self::SupportManageTemplates => 'Les réponses types proposées à toute l\'équipe dans le fil.',

            self::ChallengesCreate => 'Un challenge engage un budget de lots dès sa création.',
            self::ChallengesApproveSurprise => "Un bonus surprise attribue un prix hors classement : l'approbation est le contrôle.",
            self::ChallengesClosePeriod => 'Gèle le vivier : plus personne ne peut entrer dans le challenge.',
            self::ChallengesDraw => 'Désigne les gagnants. Le tirage ne se rejoue pas.',
            self::ChallengesRegenerateSeed => 'Change le hasard après le gel du vivier — le geste le plus sensible du module.',
            self::ChallengesCredit => "Déclare le lot remis au gagnant. Touche à ce qu'on lui doit.",
            self::ChallengesManagePrizes => 'Les lots proposés aux challenges, et leur valeur.',

            self::AnnouncementsManage => "Créer, modifier, réordonner ou supprimer une bannière de l'accueil.",
            self::AnnouncementsPublish => "Une annonce active s'affiche à tous les conducteurs.",

            self::CampaignsManage => 'Préparer un message et son audience, sans le diffuser.',
            self::CampaignsSend => 'Dépose le message dans le fil de chaque conducteur visé. Irréversible.',

            self::ShopManageCatalogue => 'Créer une référence, changer un prix, fermer une pièce à la commande.',
            self::ShopFulfilOrders => 'Préparer, expédier, livrer, remettre au comptoir.',
            self::ShopCancelOrder => 'Une annulation peut déclencher un remboursement.',

            self::RechargesReconcile => "Touche à l'argent d'un conducteur. L'accès au module n'ouvre que la lecture du journal.",

            self::SettingsManage => 'Barèmes, plafonds, et les clés Wave et Yango — les écraser coupe l\'encaissement.',
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
