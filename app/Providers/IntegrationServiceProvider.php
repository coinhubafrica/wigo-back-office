<?php

namespace App\Providers;

use App\Contracts\PushSender;
use App\Contracts\SmsSender;
use App\Contracts\WaveClient;
use App\Contracts\YangoClient;
use App\Contracts\YangoDirectory;
use App\Services\Fcm\HttpPushSender;
use App\Services\Fcm\LogPushSender;
use App\Services\Sms\HttpSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Wave\FakeWaveClient;
use App\Services\Wave\SaloonWaveClient;
use App\Services\Yango\FakeYangoClient;
use App\Services\Yango\FakeYangoDirectory;
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
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            if ($this->app->environment('testing') || config('services.sms.driver') === 'log') {
                return new LogSmsSender;
            }

            return new HttpSmsSender;
        });

        $this->app->singleton(PushSender::class, function (): PushSender {
            if ($this->app->environment('testing') || config('services.fcm.driver') === 'log') {
                return new LogPushSender;
            }

            return new HttpPushSender;
        });

        $this->app->singleton(WaveClient::class, function (): WaveClient {
            if ($this->app->environment('testing') || config('services.wave.driver') === 'fake') {
                return new FakeWaveClient;
            }

            return new SaloonWaveClient;
        });

        $this->app->singleton(YangoClient::class, function (): YangoClient {
            if ($this->app->environment('testing') || config('services.yango.driver') === 'fake') {
                return new FakeYangoClient;
            }

            return new SaloonYangoClient;
        });

        $this->app->singleton(YangoDirectory::class, function (): YangoDirectory {
            if ($this->app->environment('testing') || config('services.yango.driver') === 'fake') {
                return new FakeYangoDirectory;
            }

            return new SaloonYangoDirectory;
        });
    }
}
