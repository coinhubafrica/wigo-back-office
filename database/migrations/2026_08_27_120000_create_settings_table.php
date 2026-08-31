<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages métier modifiables depuis « Paramètres », stockés par
     * spatie/laravel-settings (une ligne par propriété, `group` + `name`).
     *
     * Ne s'y trouvent que les valeurs que le métier ajuste : seuils, délais,
     * barème SLA. Les interrupteurs de sécurité et de déploiement restent dans
     * `config/wigo.php`, pilotés par l'environnement — un contournement d'OTP
     * ou un jeton de documentation ne doit pas se changer depuis une page web.
     *
     * Migration publiée par le paquet, horodatage réaligné sur le schéma.
     */
    public function up(): void
    {
        Schema::create(config('settings.repositories.database.table') ?? 'settings', function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');

            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }
};
