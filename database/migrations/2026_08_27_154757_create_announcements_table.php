<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bannière de l'accueil mobile : image ou courte vidéo, gérée par le
     * back-office (module Annonces) et servie par l'API mobile.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('media_type', 10);
            $table->string('media_url');
            $table->unsignedInteger('order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
