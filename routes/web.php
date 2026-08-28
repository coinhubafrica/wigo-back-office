<?php

use App\Enums\BackOfficeModule;
use App\Livewire\Announcements\Index as AnnouncementsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Challenges\Index as ChallengesIndex;
use App\Livewire\Challenges\Prizes as ChallengesPrizes;
use App\Livewire\Challenges\Show as ChallengesShow;
use App\Livewire\Dashboard;
use App\Livewire\Drivers\Index as DriversIndex;
use App\Livewire\Drivers\Show as DriversShow;
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
});
