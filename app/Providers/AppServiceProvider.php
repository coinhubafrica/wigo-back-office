<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        RateLimiter::for('otp', function (Request $request): Limit {
            $throttle = (array) config('wigo.otp.throttle');

            return Limit::perMinutes((int) $throttle['decay_minutes'], (int) $throttle['max_sends'])
                ->by((string) $request->input('phone', $request->ip()))
                ->response(fn (Request $request, array $headers): JsonResponse => new JsonResponse([
                    'message' => __('otp.throttled', ['minutes' => (int) $throttle['decay_minutes']]),
                ], 429, $headers));
        });

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 20)
            ->by((string) $request->input('phone', $request->ip())));
    }

    /**
     * Accès à la documentation générée (`/docs/api`).
     *
     * Ouverte en local. Ailleurs, elle n'est servie que si `API_DOCS_TOKEN` est
     * défini et fourni en paramètre `?token=` : l'équipe mobile consulte le
     * contrat sur les environnements de recette sans l'exposer publiquement.
     */
    protected function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', function (?object $user = null): bool {
            $expected = (string) config('wigo.docs_token');

            if ($expected === '') {
                return false;
            }

            return hash_equals($expected, (string) request()->query('token'));
        });
    }
}
