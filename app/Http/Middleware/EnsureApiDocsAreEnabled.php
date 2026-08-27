<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interrupteur principal de la documentation générée.
 *
 * Scramble laisse passer l'environnement local avant de consulter la
 * autorisation `viewApiDocs` : ce middleware s'exécute en amont pour que
 * `API_DOCS_ENABLED=false` ferme réellement `/docs/api` partout.
 */
class EnsureApiDocsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('wigo.docs.enabled'), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
