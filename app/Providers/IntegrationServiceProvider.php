<?php

namespace App\Providers;

use App\Contracts\WaveClient;
use App\Contracts\YangoClient;
use App\Contracts\YangoDirectory;
use App\Services\Wave\FakeWaveClient;
use App\Services\Wave\SaloonWaveClient;
use App\Services\Yango\SaloonYangoClient;
use App\Services\Yango\SaloonYangoDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Les services externes sont résolus derrière un contrat : implémentation HTTP
 * en production, doublure locale sinon. En test, on ne sort jamais du process.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaveClient::class, function (): WaveClient {
            if ($this->app->environment('testing') || config('services.wave.driver') === 'fake') {
                return new FakeWaveClient;
            }

            return new SaloonWaveClient;
        });

        // Yango n'a pas de doublure : les tests simulent l'API par le
        // `MockClient` de Saloon, qui exerce le connecteur et le décodage au
        // lieu de les contourner (cf. `.ai/rules/yango.md`).
        $this->app->singleton(YangoClient::class, fn (): YangoClient => new SaloonYangoClient);

        $this->app->singleton(YangoDirectory::class, fn (): YangoDirectory => new SaloonYangoDirectory);
    }
}
