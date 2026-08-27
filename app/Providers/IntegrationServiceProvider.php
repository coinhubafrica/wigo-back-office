<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Sms\HttpSmsSender;
use App\Services\Sms\LogSmsSender;
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
    }
}
