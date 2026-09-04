<?php

use App\Enums\BackOfficeModule;
use App\Http\Controllers\BackOffice\AuditExportController;
use App\Http\Controllers\BackOffice\DriverPhotoController;
use App\Http\Controllers\BackOffice\MessageAttachmentController;
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

Route::redirect('/', '/login')->name('home');

/*
| Pages de retour de Wave Checkout. Le paiement est confirmé par le webhook :
| ces vues ne servent qu'à ramener l'utilisateur dans l'application mobile.
*/
Route::view('payment/success', 'wave.success')->name('wave.success');
Route::view('payment/failed', 'wave.error')->name('wave.error');

/*
|--------------------------------------------------------------------------
| Back-office
|--------------------------------------------------------------------------
|
| Session `web`, composants Livewire pleine page. Chaque module est protégé par
| sa permission `module.*` (spatie) : masquer l'entrée dans la barre latérale ne
| suffit pas, l'accès direct à l'URL doit répondre 403.
|
*/

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

    Route::livewire('shop/orders', ShopOrders::class)
        ->middleware('permission:'.BackOfficeModule::ShopOrders->permission())
        ->name(BackOfficeModule::ShopOrders->route());

    Route::livewire('campaigns', CampaignsIndex::class)
        ->middleware('permission:'.BackOfficeModule::Campaigns->permission())
        ->name(BackOfficeModule::Campaigns->route());

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
