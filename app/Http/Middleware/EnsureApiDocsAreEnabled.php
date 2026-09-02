<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interrupteur principal de la documentation.
 *
 * `EnsureApiDocsAreAuthorized` ouvre l'environnement local sans jeton ; cet
 * interrupteur-ci s'applique en amont et sans exception, pour que
 * `API_DOCS_ENABLED=false` ferme réellement la documentation partout, local
 * compris.
 */
class EnsureApiDocsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('wigo.docs.enabled'), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
