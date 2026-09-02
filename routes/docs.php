<?php

use App\Http\Controllers\Docs\DocsController;
use App\Http\Middleware\EnsureApiDocsAreAuthorized;
use App\Http\Middleware\EnsureApiDocsAreEnabled;
use Illuminate\Support\Facades\Route;

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
    ->middleware(['web', EnsureApiDocsAreEnabled::class, EnsureApiDocsAreAuthorized::class])
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
