<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Connexion des utilisateurs du back-office (guard `web`, session).
 *
 * Il n'y a ni inscription ni réinitialisation publique : les comptes sont créés
 * par la direction dans Paramètres.
 */
#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt(
            ['email' => $this->email, 'password' => $this->password, 'is_active' => true],
            $this->remember,
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('backoffice.login_failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();

        $landing = $this->landingRoute($user);

        if ($landing === null) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => __('backoffice.no_access'),
            ]);
        }

        $this->redirectRoute($landing, navigate: true);
    }

    /**
     * Première route accessible à l'utilisateur.
     *
     * Les modules sont livrés progressivement : on ignore ceux dont la route
     * n'existe pas encore, plutôt que de rediriger vers une route absente.
     */
    private function landingRoute(User $user): ?string
    {
        foreach ($user->visibleModules() as $module) {
            if (Route::has($module->route())) {
                return $module->route();
            }
        }

        return null;
    }

    /**
     * Cinq tentatives par minute et par couple e-mail + IP.
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($this->throttleKey()),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
