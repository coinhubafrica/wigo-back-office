<?php

namespace App\Providers;

use App\Contracts\FleetClient;
use App\Contracts\SmsSender;
use App\Contracts\WaveClient;
use App\Services\Fleet\FakeFleetClient;
use App\Services\Fleet\HttpFleetClient;
use App\Services\Sms\HttpSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Wave\FakeWaveClient;
use App\Services\Wave\HttpWaveClient;
use Illuminate\Support\ServiceProvider;

/**
 * Les services externes sont résolus derrière un contrat : implémentation HTTP
 * en production, doublure locale sinon. En test, on ne sort jamais du process.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            if ($this->app->environment('testing') || config('services.sms.driver') === 'log') {
                return new LogSmsSender;
            }

            return new HttpSmsSender;
        });

        $this->app->singleton(WaveClient::class, function (): WaveClient {
            if ($this->app->environment('testing') || config('services.wave.driver') === 'fake') {
                return new FakeWaveClient;
            }

            return new HttpWaveClient;
        });

        $this->app->singleton(FleetClient::class, function (): FleetClient {
            if ($this->app->environment('testing') || config('services.fleet.driver') === 'fake') {
                return new FakeFleetClient;
            }

            return new HttpFleetClient;
        });
    }
}
