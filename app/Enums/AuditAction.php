<?php

namespace App\Enums;

/**
 * Catalogue des gestes portés au journal d'audit.
 *
 * **Quand un geste mérite une ligne.** Quand une personne raisonnable pourrait
 * le contester plus tard : il a déplacé de l'argent, coupé un revenu, changé
 * qui peut quoi, atteint tout le parc, ou il est irréversible. Un simple
 * enregistrement, un fait que l'état de l'objet consigne déjà, ou un geste
 * répété des dizaines de fois par jour, non — un journal trop plein ne se lit
 * pas, et un journal illisible ne prouve rien. C'est le raisonnement déjà écrit
 * dans `Users\Index::recordSave()` : « une faute de frappe sur un nom n'a pas à
 * encombrer le journal ».
 *
 * **Pourquoi une énumération plutôt qu'un `distinct('action')`.** L'écran a
 * besoin d'un libellé français par geste, d'un rattachement au module pour
 * grouper ses filtres, et d'une teinte de pastille — trois faits que le SQL ne
 * porte pas. Surtout : les valeurs présentes en base dépendent de ce qui s'est
 * produit sur *cette* installation. Un filtre dérivé du SQL s'ouvrirait donc
 * presque vide sur une base neuve et ferait croire le journal cassé, tandis
 * qu'une faute de frappe dans un slug d'appel y créerait silencieusement une
 * option.
 *
 * **Mais un catalogue souple.** `AuditLog::record()` garde `string $action`, la
 * colonne n'a aucune contrainte, et la lecture passe par `tryFrom()` via
 * {@see self::labelFor()}. La table est en ajout seul et jamais purgée : une
 * ligne écrite par un code disparu depuis doit rester affichable. Un slug hors
 * catalogue se dégrade en libellé brut — il ne lève pas.
 */
enum AuditAction: string
{
    // Chauffeurs — suspendre coupe le revenu d'un conducteur.
    case DriverSuspended = 'driver.suspended';
    case DriverReactivated = 'driver.reactivated';

    /*
    | Requêtes. Répondre n'est pas journalisé : le message *est* sa propre
    | trace, horodatée et attribuée dans le fil. Ne restent que les gestes
    | qu'aucune ligne ne consigne — écarter sans réponse, et déplacer la charge
    | d'autrui.
    */
    case SupportDismissed = 'support.dismissed';
    case SupportReassigned = 'support.reassigned';
    case SupportTemplateDeleted = 'support.template_deleted';

    // Challenges — le cycle de vie d'une gratification, geste par geste.
    case ChallengeCreated = 'challenge.created';
    case ChallengeApproved = 'challenge.approved';
    case ChallengeRejected = 'challenge.rejected';
    case ChallengePeriodClosed = 'challenge.period_closed';
    case ChallengeDrawn = 'challenge.drawn';
    case ChallengeSeedRegenerated = 'challenge.seed_regenerated';
    case ChallengePrizeCredited = 'challenge.prize_credited';
    case ChallengePrizeDeleted = 'challenge.prize_deleted';

    // Annonces — ce que tout le parc voit à l'accueil.
    case AnnouncementPublished = 'announcement.published';
    case AnnouncementWithdrawn = 'announcement.withdrawn';
    case AnnouncementDeleted = 'announcement.deleted';

    // Campagnes — rédiger un brouillon n'atteint personne ; diffuser, si.
    case CampaignSent = 'campaign.sent';
    case CampaignRecipientsReplayed = 'campaign.recipients_replayed';

    /*
    | Boutique. Le *mouvement de prix* est journalisé, pas l'enregistrement :
    | un prix est ce qu'un conducteur paie, tandis que créer une référence ou
    | corriger son libellé laisse la ligne elle-même comme preuve.
    */
    case ShopPriceChanged = 'shop.price_changed';
    case ShopProductDeleted = 'shop.product_deleted';
    case ShopOrderCancelled = 'shop.order_cancelled';

    // Finance — l'argent d'un conducteur.
    case RechargeReplayed = 'recharge.replayed';
    case RechargeMarkedCredited = 'recharge.marked_credited';
    case RechargeCredited = 'recharge.credited';
    case RechargeYangoFailed = 'recharge.yango_failed';

    /*
    | Système. Les enregistrements de réglages porteurs de secrets ne
    | journalisent que le *nom* des champs remplacés, jamais leur valeur — même
    | règle que la révélation en clair.
    */
    case SettingsSecretRevealed = 'settings.secret_revealed';
    case SettingsWaveShopUpdated = 'settings.wave_shop_updated';
    case SettingsWaveTopupUpdated = 'settings.wave_topup_updated';
    case SettingsRechargeUpdated = 'settings.recharge_updated';
    case SettingsYangoUpdated = 'settings.yango_updated';
    case SettingsOtpUpdated = 'settings.otp_updated';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserEnabled = 'user.enabled';
    case UserDisabled = 'user.disabled';
    case UserPasswordReset = 'user.password_reset';

    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';

    // Le journal auditant sa propre copie : c'est le geste le plus sensible
    // qu'offre le module, et le seul de son écran qui écrive.
    case AuditExported = 'audit.exported';

    /**
     * Le geste, tel qu'il s'affiche en pastille et en tête de colonne du CSV.
     *
     * Un `match` sans `default` : un cas ajouté sans libellé lève ici, au
     * premier affichage, plutôt que de rendre une pastille vide.
     */
    public function label(): string
    {
        return match ($this) {
            self::DriverSuspended => 'Conducteur suspendu',
            self::DriverReactivated => 'Conducteur réactivé',

            self::SupportDismissed => 'Messages écartés sans réponse',
            self::SupportReassigned => 'Requête réattribuée',
            self::SupportTemplateDeleted => 'Réponse type supprimée',

            self::ChallengeCreated => 'Challenge créé',
            self::ChallengeApproved => 'Challenge approuvé',
            self::ChallengeRejected => 'Challenge rejeté',
            self::ChallengePeriodClosed => 'Période close',
            self::ChallengeDrawn => 'Tirage exécuté',
            self::ChallengeSeedRegenerated => 'Graine republiée',
            self::ChallengePrizeCredited => 'Lot crédité',
            self::ChallengePrizeDeleted => 'Lot supprimé',

            self::AnnouncementPublished => 'Annonce publiée',
            self::AnnouncementWithdrawn => 'Annonce retirée',
            self::AnnouncementDeleted => 'Annonce supprimée',

            self::CampaignSent => 'Campagne diffusée',
            self::CampaignRecipientsReplayed => 'Échecs de campagne rejoués',

            self::ShopPriceChanged => 'Prix modifié',
            self::ShopProductDeleted => 'Référence supprimée',
            self::ShopOrderCancelled => 'Commande annulée',

            self::RechargeReplayed => 'Crédit rejoué',
            self::RechargeMarkedCredited => 'Recharge marquée créditée',
            self::RechargeCredited => 'Recharge créditée',
            self::RechargeYangoFailed => 'Crédit Yango refusé',

            self::SettingsSecretRevealed => 'Secret relevé en clair',
            self::SettingsWaveShopUpdated => 'Clés Wave boutique remplacées',
            self::SettingsWaveTopupUpdated => 'Clés Wave recharge remplacées',
            self::SettingsRechargeUpdated => 'Plafonds de recharge modifiés',
            self::SettingsYangoUpdated => 'Accès Yango modifié',
            self::SettingsOtpUpdated => 'Barème OTP modifié',

            self::UserCreated => 'Compte créé',
            self::UserUpdated => 'Compte modifié',
            self::UserEnabled => 'Compte réactivé',
            self::UserDisabled => 'Compte désactivé',
            self::UserPasswordReset => 'Mot de passe réinitialisé',

            self::RoleCreated => 'Rôle créé',
            self::RoleUpdated => 'Rôle modifié',
            self::RoleDeleted => 'Rôle supprimé',

            self::AuditExported => 'Journal exporté',
        };
    }

    /**
     * Le module sous lequel ce geste se filtre à l'écran.
     *
     * Sans `default`, pour la même raison que `label()`.
     */
    public function belongsTo(): BackOfficeModule
    {
        return match ($this) {
            self::DriverSuspended,
            self::DriverReactivated => BackOfficeModule::Drivers,

            self::SupportDismissed,
            self::SupportReassigned,
            self::SupportTemplateDeleted => BackOfficeModule::SupportRequests,

            self::ChallengeCreated,
            self::ChallengeApproved,
            self::ChallengeRejected,
            self::ChallengePeriodClosed,
            self::ChallengeDrawn,
            self::ChallengeSeedRegenerated,
            self::ChallengePrizeCredited,
            self::ChallengePrizeDeleted => BackOfficeModule::Challenges,

            self::AnnouncementPublished,
            self::AnnouncementWithdrawn,
            self::AnnouncementDeleted => BackOfficeModule::Announcements,

            self::CampaignSent,
            self::CampaignRecipientsReplayed => BackOfficeModule::Campaigns,

            self::ShopPriceChanged,
            self::ShopProductDeleted => BackOfficeModule::Shop,

            // L'annulation relève des Commandes, pas du catalogue : c'est elle
            // qui peut rembourser.
            self::ShopOrderCancelled => BackOfficeModule::ShopOrders,

            self::RechargeReplayed,
            self::RechargeMarkedCredited,
            self::RechargeCredited,
            self::RechargeYangoFailed => BackOfficeModule::Recharges,

            self::SettingsSecretRevealed,
            self::SettingsWaveShopUpdated,
            self::SettingsWaveTopupUpdated,
            self::SettingsRechargeUpdated,
            self::SettingsYangoUpdated,
            self::SettingsOtpUpdated => BackOfficeModule::Settings,

            self::UserCreated,
            self::UserUpdated,
            self::UserEnabled,
            self::UserDisabled,
            self::UserPasswordReset,
            self::RoleCreated,
            self::RoleUpdated,
            self::RoleDeleted => BackOfficeModule::Users,

            self::AuditExported => BackOfficeModule::Audit,
        };
    }

    /**
     * Paire complète `bg-… text-…` sur les jetons de la charte.
     *
     * Le rouge dit « quelque chose a été coupé ou a échoué », l'orange « on a
     * touché à un secret ou au hasard d'un tirage » — les deux familles qu'un
     * relecteur cherche en premier. Le reste reste neutre : un journal qui se
     * remplit est normal, et tout colorer ne distinguerait plus rien.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::DriverSuspended,
            self::UserDisabled,
            self::SupportDismissed,
            self::ChallengeRejected,
            self::ChallengePrizeDeleted,
            self::AnnouncementDeleted,
            self::ShopProductDeleted,
            self::ShopOrderCancelled,
            self::SupportTemplateDeleted,
            self::RoleDeleted,
            self::RechargeYangoFailed => 'bg-err-bg text-err-text',

            self::SettingsSecretRevealed,
            self::SettingsWaveShopUpdated,
            self::SettingsWaveTopupUpdated,
            self::SettingsRechargeUpdated,
            self::SettingsYangoUpdated,
            self::SettingsOtpUpdated,
            self::ChallengeSeedRegenerated,
            self::ShopPriceChanged,
            self::UserPasswordReset,
            self::RoleCreated,
            self::RoleUpdated,
            self::AuditExported => 'bg-warn-bg text-warn-text',

            self::DriverReactivated,
            self::UserEnabled,
            self::RechargeCredited,
            self::RechargeMarkedCredited,
            self::ChallengePrizeCredited => 'bg-ok-bg text-ok-text',

            self::UserCreated,
            self::UserUpdated,
            self::SupportReassigned,
            self::ChallengeCreated,
            self::ChallengeApproved,
            self::ChallengePeriodClosed,
            self::ChallengeDrawn,
            self::AnnouncementPublished,
            self::AnnouncementWithdrawn,
            self::CampaignSent,
            self::CampaignRecipientsReplayed,
            self::RechargeReplayed => 'bg-neutral-bg text-neutral-text',
        };
    }

    /**
     * Les gestes rattachés à ce module, pour sa rangée de puces.
     *
     * @return list<self>
     */
    public static function forModule(BackOfficeModule $module): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $action): bool => $action->belongsTo() === $module,
        ));
    }

    /**
     * Les modules qui portent au moins un geste journalisable.
     *
     * Une puce de filtre qui ne peut rien rendre est du bruit : les modules en
     * pure lecture (Tableau de bord, Véhicules, CNPS) n'en ont pas.
     *
     * @return list<BackOfficeModule>
     */
    public static function modules(): array
    {
        $modules = [];

        foreach (self::cases() as $action) {
            $module = $action->belongsTo();

            if (! in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * Libellé d'un slug lu en base, connu du code ou non.
     *
     * **L'unique porte de lecture** : la vue et l'export passent par ici, jamais
     * par `from()`. La table est en ajout seul et jamais purgée — une ligne
     * écrite par un code retiré depuis doit s'afficher, fût-ce sous son slug
     * brut, plutôt que de faire tomber la page.
     */
    public static function labelFor(string $action): string
    {
        return self::tryFrom($action)?->label() ?? $action;
    }

    /**
     * Teinte d'un slug lu en base. Même tolérance que `labelFor()`.
     */
    public static function badgeClassesFor(string $action): string
    {
        return self::tryFrom($action)?->badgeClasses() ?? 'bg-neutral-bg text-neutral-text';
    }
}
