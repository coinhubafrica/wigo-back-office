<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Conversation;
use App\Models\Delivery;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\IdempotencyKey;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTemplate;
use App\Models\OtpCode;
use App\Models\PartCategory;
use App\Models\PickupPoint;
use App\Models\Prize;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\SupportRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\YangoOrder;
use App\Models\YangoTransaction;
use App\Settings\OtpSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureModels();
        $this->configureRateLimiting();
        $this->configureApiDocs();
        $this->configureAuthorization();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Les modèles déclarent leur propre `$guarded` : la protection contre
     * l'assignation en masse reste active, les payloads étant filtrés par les
     * Form Requests.
     */
    protected function configureModels(): void
    {
        Model::shouldBeStrict(! app()->isProduction());

        /*
        | Alias de morph : les colonnes `*_type` portent un nom court et stable
        | ('user', 'driver', 'transaction'…) plutôt qu'un nom de classe. Deux
        | raisons : renommer ou déplacer un modèle ne casse plus les lignes déjà
        | écrites, et le contrat mobile ne publie pas la structure du code.
        |
        | `enforceMorphMap` et non `morphMap` : la variante stricte lève dès
        | qu'un modèle absent de la carte atterrit dans une colonne polymorphe.
        | C'est exactement ce qu'on veut — `AuditLog::record()` accepte
        | n'importe quel `Model`, et un oubli doit se voir au test, pas en
        | production. La carte est donc exhaustive : tout nouveau modèle
        | s'ajoute ici.
        */
        Relation::enforceMorphMap([
            'announcement' => Announcement::class,
            'audit_log' => AuditLog::class,
            'campaign' => Campaign::class,
            'campaign_recipient' => CampaignRecipient::class,
            'challenge' => Challenge::class,
            'challenge_ticket' => ChallengeTicket::class,
            'challenge_winner' => ChallengeWinner::class,
            'cnps_declaration' => CnpsDeclaration::class,
            'cnps_reference' => CnpsReference::class,
            'conversation' => Conversation::class,
            'delivery' => Delivery::class,
            'driver' => Driver::class,
            'driver_daily_activity' => DriverDailyActivity::class,
            'idempotency_key' => IdempotencyKey::class,
            'message' => Message::class,
            'message_attachment' => MessageAttachment::class,
            'message_template' => MessageTemplate::class,
            'otp_code' => OtpCode::class,
            'part_category' => PartCategory::class,
            'pickup_point' => PickupPoint::class,
            'prize' => Prize::class,
            'product' => Product::class,
            // Les rôles sont sujets de lignes d'audit (matrice des droits) :
            // `enforceMorphMap` lèverait sans cette entrée.
            'role' => Role::class,
            'shop_order' => ShopOrder::class,
            'shop_order_item' => ShopOrderItem::class,
            'support_request' => SupportRequest::class,
            'transaction' => Transaction::class,
            'user' => User::class,
            'vehicle' => Vehicle::class,
            'vehicle_brand' => VehicleBrand::class,
            'vehicle_model' => VehicleModel::class,
            'yango_order' => YangoOrder::class,
            'yango_transaction' => YangoTransaction::class,
        ]);
    }

    /**
     * Limites du contrat mobile : 60 requêtes/minute par jeton et 3 ENVOIS d'OTP
     * par tranche de 10 minutes et par numéro. La vérification du code a son
     * propre quota, plus large : le plafond d'envoi ne doit pas empêcher un
     * conducteur de corriger une faute de frappe (le verrouillage après N échecs
     * est géré par OtpService).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('mobile', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Les réglages sont résolus dans la fermeture, pas à l'enregistrement :
        // la table `settings` n'est pas forcément lue au démarrage (migrations,
        // `config:cache`), et une modification depuis « Paramètres » doit
        // s'appliquer sans redéploiement.
        RateLimiter::for('otp', function (Request $request): Limit {
            $settings = app(OtpSettings::class);

            return Limit::perMinutes($settings->throttle_decay_minutes, $settings->throttle_max_sends)
                ->by((string) $request->input('phone', $request->ip()))
                ->response(fn (Request $request, array $headers): JsonResponse => new JsonResponse([
                    'message' => __('otp.throttled', ['minutes' => $settings->throttle_decay_minutes]),
                ], 429, $headers));
        });

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 20)
            ->by((string) $request->input('phone', $request->ip())));
    }

    /**
     * Accès à la documentation (`/docs/api`) hors environnement local.
     *
     * L'interrupteur principal (`API_DOCS_ENABLED`) est appliqué en amont par
     * EnsureApiDocsAreEnabled. Ici, on n'autorise que sur présentation du jeton
     * `API_DOCS_TOKEN` : l'équipe mobile consulte le contrat en recette sans
     * l'exposer publiquement.
     */
    protected function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', function (?object $user = null): bool {
            $expected = (string) config('wigo.docs.token');

            if ($expected === '') {
                return false;
            }

            return hash_equals($expected, (string) request()->query('token'));
        });
    }

    /**
     * Portails des actions sensibles.
     *
     * Chacun consulte une permission de `Permission`, jamais un nom de rôle :
     * les rôles s'administrent à l'écran (« Utilisateurs et rôles »), un
     * `hasRole('direction')` en dur figeait une décision d'organisation dans le
     * code et rendait la matrice des rôles mensongère — on cochait une case
     * sans effet.
     *
     * Les noms de portails sont conservés : ils sont appelés depuis les
     * composants Livewire et les vues.
     */
    protected function configureAuthorization(): void
    {
        $gates = [
            // Chauffeurs — une suspension coupe le revenu du conducteur.
            'suspendDriver' => Permission::DriversSuspend,

            // Requêtes. Traiter (répondre, recatégoriser, résoudre) et écarter
            // sans réponse sont deux décisions : la seconde laisse le
            // conducteur sans réponse.
            'handleSupportRequest' => Permission::SupportHandle,
            'dismissSupportMessage' => Permission::SupportDismiss,
            // Un agent reprend le sien à son compte (« M'assigner », ouvert à
            // tous) ; désigner un *autre* destinataire est un acte
            // d'encadrement — c'est répartir la charge de l'équipe.
            'reassignSupportRequest' => Permission::SupportReassign,
            'manageSupportTemplates' => Permission::SupportManageTemplates,

            /*
            | Challenges : le cycle de vie d'une gratification, geste par
            | geste. Créer engage un budget ; clore gèle le vivier ; tirer
            | désigne les gagnants ; créditer touche à ce qu'on leur doit.
            | Republier la graine change le hasard après le gel — le geste le
            | plus sensible du module.
            */
            'createChallenge' => Permission::ChallengesCreate,
            // Un bonus surprise attribue un prix hors classement : l'accès au
            // module (partagé avec le rôle bonus) n'ouvre pas l'approbation.
            'approveSurpriseChallenge' => Permission::ChallengesApproveSurprise,
            'closeChallengePeriod' => Permission::ChallengesClosePeriod,
            'drawChallenge' => Permission::ChallengesDraw,
            'regenerateChallengeSeed' => Permission::ChallengesRegenerateSeed,
            'creditChallengePrize' => Permission::ChallengesCredit,
            'manageChallengePrizes' => Permission::ChallengesManagePrizes,

            // Annonces — publier expose la bannière à tous les conducteurs.
            'manageAnnouncements' => Permission::AnnouncementsManage,
            'publishAnnouncement' => Permission::AnnouncementsPublish,

            // Campagnes — rédiger un brouillon n'est pas le diffuser.
            'manageCampaigns' => Permission::CampaignsManage,
            'sendCampaign' => Permission::CampaignsSend,

            // `module.shop` ouvre le catalogue en lecture à tous les profils qui
            // suivent la boutique ; écrire — créer une référence, la fermer à la
            // commande — est un droit à part. Faire avancer une commande relève
            // du module Commandes, et l'annuler peut rembourser.
            'manageCatalogue' => Permission::ShopManageCatalogue,
            'fulfilShopOrder' => Permission::ShopFulfilOrders,
            'cancelShopOrder' => Permission::ShopCancelOrder,

            // Rejouer un crédit ou marquer une transaction créditée touche à
            // l'argent d'un conducteur. `module.recharges` n'ouvre que la
            // lecture du journal.
            'reconcileRecharges' => Permission::RechargesReconcile,

            /*
            | Réglages. Écraser une clé d'encaissement est au moins aussi grave
            | que la lire : `settings.reveal-secrets` ne gardait que la lecture,
            | et n'importe quel accès au module pouvait remplacer la clé Wave.
            */
            'manageSettings' => Permission::SettingsManage,
            'revealSettingsSecrets' => Permission::SettingsRevealSecrets,

            // Tenir les comptes du back-office, et décider qui peut quoi.
            'manageUsers' => Permission::UsersManage,
            'manageRoles' => Permission::RolesManage,

            // Relire le journal à l'écran laisse la trace dans l'application ;
            // l'exporter l'en fait sortir dans un fichier qui se transmet.
            'exportAuditLog' => Permission::AuditExport,
        ];

        foreach ($gates as $ability => $permission) {
            Gate::define($ability, fn (User $user): bool => $user->can($permission->value));
        }
    }
}
