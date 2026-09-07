<?php

use App\Enums\BackOfficeModule;
use App\Http\Controllers\BackOffice\AuditExportController;
use App\Http\Controllers\BackOffice\CampaignImageController;
use App\Http\Controllers\BackOffice\ChallengeRulesDocumentController;
use App\Http\Controllers\BackOffice\DriverPhotoController;
use App\Http\Controllers\BackOffice\MessageAttachmentController;
use App\Http\Controllers\BackOffice\ShopOrderDocumentController;
use App\Http\Controllers\Docs\DocsController;
use App\Http\Middleware\EnsureApiDocsAreAuthorized;
use App\Http\Middleware\EnsureApiDocsAreEnabled;
use App\Livewire\Announcements\Index as AnnouncementsIndex;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Campaigns\Index as CampaignsIndex;
use App\Livewire\Campaigns\Show as CampaignsShow;
use App\Livewire\Challenges\Index as ChallengesIndex;
use App\Livewire\Challenges\Prizes as ChallengesPrizes;
use App\Livewire\Challenges\Show as ChallengesShow;
use App\Livewire\Cnps\Index as CnpsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Drivers\Index as DriversIndex;
use App\Livewire\Drivers\Show as DriversShow;
use App\Livewire\Recharges\Index as RechargesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Shop\Catalogue as ShopCatalogue;
use App\Livewire\Shop\Orders as ShopOrders;
use App\Livewire\SupportRequests\Index as SupportRequestsIndex;
use App\Livewire\SupportRequests\Templates as SupportRequestsTemplates;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Users\Roles as UsersRoles;
use App\Livewire\Vehicles\Index as VehiclesIndex;
use App\Livewire\Vehicles\Show as VehiclesShow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Site vitrine — wigo.ci
|--------------------------------------------------------------------------
|
| La page publique du parc, seule route de ce domaine. Aucun compte, aucune
| donnée : les chiffres sont figés dans la vue. Une vue et non un composant
| Livewire — la page n'a aucun état serveur, et c'est déjà le motif des pages
| de retour de paiement.
|
| ORDRE SIGNIFICATIF : ce groupe est déclaré AVANT celui du back-office, qui
| pose lui aussi un `GET /` (`bo.home`). En production les domaines les
| séparent ; en local, les deux contraintes d'hôte tombent et le premier
| déclaré gagne — donc la vitrine. Réordonner ces deux groupes changerait le
| comportement local sans qu'aucun test de production ne bronche.
| Cf. `tests/Feature/Site/DomainRoutingTest.php`.
|
*/

Route::domain(config('wigo.domains.site'))->group(function (): void {
    Route::view('/', 'site.home')->name('site.home');
});

/*
|--------------------------------------------------------------------------
| Back-office, API mobile, documentation — support.wigo.ci
|--------------------------------------------------------------------------
|
| Tout ce qui n'est pas la vitrine. Session `web`, composants Livewire pleine
| page. Chaque module est protégé par sa permission `module.*` (spatie) :
| masquer l'entrée dans la barre latérale ne suffit pas, l'accès direct à
| l'URL doit répondre 403.
|
| Une route de ce groupe demandée sur `wigo.ci` ne trouve pas de
| correspondance : 404 par le routeur, sans redirection ni fuite.
|
| `/up` (sonde de santé, déclarée par `withRouting(health:)`) reste
| volontairement hors de ce groupe : la plateforme la sonde parfois par nom
| interne ou par IP, et une contrainte d'hôte ferait échouer le contrôle.
|
*/

Route::domain(config('wigo.domains.back_office'))->group(function (): void {

    /*
    | L'hôte nu du back-office mène à la connexion.
    |
    | Conditionné à la présence du domaine, et ce n'est pas une précaution
    | de style : `Route::redirect()` enregistre TOUS les verbes (`ANY`), et
    | `RouteCollection` indexe par méthode. Déclarée après la vitrine mais
    | sans contrainte d'hôte — le cas local — cette route écrase donc l'entrée
    | `GET /` de `site.home` et la page publique devient injoignable. L'ordre
    | de déclaration ne protège pas de cela : la condition, si.
    |
    | Vérifié par `tests/Feature/Site/DomainRoutingTest.php`.
    */
    if (config('wigo.domains.back_office') !== null) {
        Route::redirect('/', '/login')->name('bo.home');
    }

    /*
    | Pages de retour de Wave Checkout. Le paiement est confirmé par le
    | webhook : ces vues ne servent qu'à ramener l'utilisateur dans
    | l'application mobile.
    |
    | `SaloonWaveClient` fige ces URL par `route()` au moment de créer la
    | session Checkout : elles suivent donc ce domaine pour toute session
    | créée après le déploiement. Une session ouverte AVANT la bascule porte
    | l'ancienne URL absolue et retombera sur un 404 — impact cosmétique, le
    | webhook créditant de son côté.
    */
    Route::view('payment/success', 'wave.success')->name('wave.success');
    Route::view('payment/failed', 'wave.error')->name('wave.error');
    Route::middleware('guest')->group(function (): void {
        Route::livewire('login', Login::class)->name('bo.login');
    });

    Route::middleware(['auth', 'user.active'])->group(function (): void {
        Route::post('logout', function () {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('bo.login');
        })->name('bo.logout');

        Route::livewire('dashboard', Dashboard::class)
            ->middleware('permission:'.BackOfficeModule::Dashboard->permission())
            ->name(BackOfficeModule::Dashboard->route());

        Route::livewire('drivers', DriversIndex::class)
            ->middleware('permission:'.BackOfficeModule::Drivers->permission())
            ->name(BackOfficeModule::Drivers->route());

        Route::livewire('drivers/{driver}', DriversShow::class)
            ->middleware('permission:'.BackOfficeModule::Drivers->permission())
            ->name('bo.drivers.show');

        /*
        | La photo de profil vit sur le disque privé : la fiche ne peut pas la
        | pointer directement, elle passe par cette route protégée.
        |
        | Deux modules l'affichent — la fiche du conducteur et les avatars du fil
        | de support : la route accepte l'une ou l'autre permission (spatie lit le
        | `|` comme un « ou »). La borner aux seuls Conducteurs cassait l'avatar
        | d'un agent qui ne fait que du support.
        */
        Route::get('drivers/{driver}/photo', DriverPhotoController::class)
            ->middleware('permission:'.implode('|', [
                BackOfficeModule::Drivers->permission(),
                BackOfficeModule::SupportRequests->permission(),
            ]))
            ->name('bo.drivers.photo');

        Route::livewire('vehicles', VehiclesIndex::class)
            ->middleware('permission:'.BackOfficeModule::Vehicles->permission())
            ->name(BackOfficeModule::Vehicles->route());

        Route::livewire('vehicles/{vehicle}', VehiclesShow::class)
            ->middleware('permission:'.BackOfficeModule::Vehicles->permission())
            ->name('bo.vehicles.show');

        Route::livewire('announcements', AnnouncementsIndex::class)
            ->middleware('permission:'.BackOfficeModule::Announcements->permission())
            ->name(BackOfficeModule::Announcements->route());

        Route::livewire('challenges', ChallengesIndex::class)
            ->middleware('permission:'.BackOfficeModule::Challenges->permission())
            ->name(BackOfficeModule::Challenges->route());

        Route::livewire('challenges/lots', ChallengesPrizes::class)
            ->middleware('permission:'.BackOfficeModule::Challenges->permission())
            ->name('bo.challenges.prizes');

        // Le règlement joint à un challenge vit sur un disque privé : l'écran ne
        // peut pas le pointer directement, il passe par cette route protégée.
        // Déclarée avant la route de détail, sinon `{challenge}` l'absorbe.
        Route::get('challenges/{challenge}/reglement', ChallengeRulesDocumentController::class)
            ->middleware('permission:'.BackOfficeModule::Challenges->permission())
            ->name('bo.challenges.rules-document');

        Route::livewire('challenges/{challenge}', ChallengesShow::class)
            ->middleware('permission:'.BackOfficeModule::Challenges->permission())
            ->name('bo.challenges.show');

        Route::livewire('cnps', CnpsIndex::class)
            ->middleware('permission:'.BackOfficeModule::Cnps->permission())
            ->name(BackOfficeModule::Cnps->route());

        Route::livewire('recharges', RechargesIndex::class)
            ->middleware('permission:'.BackOfficeModule::Recharges->permission())
            ->name(BackOfficeModule::Recharges->route());

        Route::livewire('shop', ShopCatalogue::class)
            ->middleware('permission:'.BackOfficeModule::Shop->permission())
            ->name(BackOfficeModule::Shop->route());

        // La carte grise jointe à une commande vit sur un disque privé : l'écran
        // ne peut pas la pointer directement, elle passe par cette route protégée.
        Route::get('shop/orders/documents/{document}', ShopOrderDocumentController::class)
            ->middleware('permission:'.BackOfficeModule::ShopOrders->permission())
            ->name('bo.shop-orders.document');

        Route::livewire('shop/orders', ShopOrders::class)
            ->middleware('permission:'.BackOfficeModule::ShopOrders->permission())
            ->name(BackOfficeModule::ShopOrders->route());

        Route::livewire('campaigns', CampaignsIndex::class)
            ->middleware('permission:'.BackOfficeModule::Campaigns->permission())
            ->name(BackOfficeModule::Campaigns->route());

        // Déclarée avant `campaigns/{campaign}` : le joker avalerait sinon ce
        // chemin et l'image passerait pour un identifiant de campagne. L'image vit
        // sur un disque privé, d'où cette route protégée plutôt qu'un lien direct.
        Route::get('campaigns/image/{campaign}', CampaignImageController::class)
            ->middleware('permission:'.BackOfficeModule::Campaigns->permission())
            ->name('bo.campaigns.image');

        Route::livewire('campaigns/{campaign}', CampaignsShow::class)
            ->middleware('permission:'.BackOfficeModule::Campaigns->permission())
            ->name('bo.campaigns.show');

        Route::livewire('support-requests', SupportRequestsIndex::class)
            ->middleware('permission:'.BackOfficeModule::SupportRequests->permission())
            ->name(BackOfficeModule::SupportRequests->route());

        Route::livewire('support-requests/reponses-types', SupportRequestsTemplates::class)
            ->middleware('permission:'.BackOfficeModule::SupportRequests->permission())
            ->name('bo.support-requests.templates');

        // Les pièces jointes vivent sur un disque privé : le fil ne peut pas les
        // pointer directement, elles passent par cette route protégée.
        Route::get('support-requests/attachments/{attachment}', MessageAttachmentController::class)
            ->middleware('permission:'.BackOfficeModule::SupportRequests->permission())
            ->name('bo.support-requests.attachment');

        /*
        | Comptes du back-office et matrice des droits. Deux pages : la liste des
        | utilisateurs et l'éditeur de rôles. La permission du module ouvre la
        | lecture ; écrire demande en plus `users.manage` / `roles.manage`.
        */
        Route::livewire('users', UsersIndex::class)
            ->middleware('permission:'.BackOfficeModule::Users->permission())
            ->name(BackOfficeModule::Users->route());

        Route::livewire('users/roles', UsersRoles::class)
            ->middleware('permission:'.BackOfficeModule::Users->permission())
            ->name('bo.users.roles');

        Route::livewire('settings', SettingsIndex::class)
            ->middleware('permission:'.BackOfficeModule::Settings->permission())
            ->name(BackOfficeModule::Settings->route());

        /*
        | Journal d'audit. La permission du module ouvre la relecture à l'écran ;
        | l'export emporte le journal filtré dans un fichier et demande en plus
        | `audit.export`, que le contrôleur vérifie lui-même — le 403 porte ainsi
        | le même corps dans les deux cas, sans dire lequel des droits manquait.
        */
        Route::livewire('audit', AuditIndex::class)
            ->middleware('permission:'.BackOfficeModule::Audit->permission())
            ->name(BackOfficeModule::Audit->route());

        Route::get('audit/export', AuditExportController::class)
            ->middleware('permission:'.BackOfficeModule::Audit->permission())
            ->name('bo.audit.export');
    });

    /*
    |--------------------------------------------------------------------------
    | Documentation de l'API mobile
    |--------------------------------------------------------------------------
    |
    | Le contrat est écrit à la main sous `docs/api/` et les guides vivent dans
    | `docs/*.md` ; ces routes ne font que les publier. Deux verrous, dans cet
    | ordre : `API_DOCS_ENABLED` ferme tout, partout, local compris ; puis le
    | jeton `?token=` est exigé hors local.
    |
    | `/docs/api` et `/docs/api.json` gardent les URL déjà consommées par
    | l'équipe mobile — ne pas les renommer.
    |
    */
    Route::prefix('docs')
        ->name('docs.')
        ->middleware([EnsureApiDocsAreEnabled::class, EnsureApiDocsAreAuthorized::class])
        ->group(function (): void {
            Route::get('api', [DocsController::class, 'reference'])->name('reference');
            Route::get('api.json', [DocsController::class, 'spec'])->name('spec');
            Route::get('api/guides/{slug}', [DocsController::class, 'guide'])->name('guide');
            // Le segment `reference/` évite de partager un niveau avec
            // `guides/{slug}` : sans lui, un futur slug de guide ou un tag nommé
            // « guides » entrerait en collision, et l'ordre de déclaration
            // trancherait en silence.
            Route::get('api/reference/{tag}', [DocsController::class, 'tag'])
                ->where('tag', '[a-z0-9-]+')
                ->name('tag');
            Route::get('api/reference/{tag}/{operation}', [DocsController::class, 'operation'])
                ->where(['tag' => '[a-z0-9-]+', 'operation' => '[a-z0-9.\-]+'])
                ->name('operation');
        });
});
