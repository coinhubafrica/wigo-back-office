<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second verrou de la documentation : le jeton de consultation.
 *
 * Le local reste ouvert — l'équipe travaille sur son poste sans jeton — et
 * ailleurs il faut `?token=` correspondant à `API_DOCS_TOKEN`, vérifié par
 * l'autorisation `viewApiDocs`. L'interrupteur principal
 * (`EnsureApiDocsAreEnabled`) s'applique en amont et n'est pas court-circuité
 * par cette ouverture locale : les deux verrous sont indépendants.
 */
class EnsureApiDocsAreAuthorized
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->isLocal() || Gate::allows('viewApiDocs'), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
