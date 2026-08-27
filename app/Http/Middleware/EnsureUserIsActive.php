<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utilisateur désactivé après sa connexion est déconnecté au prochain accès :
 * les comptes ne sont jamais supprimés, seulement désactivés.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('bo.login')->withErrors([
                'email' => __('backoffice.account_disabled'),
            ]);
        }

        return $next($request);
    }
}
