<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Véhicule du parc, synchronisé depuis Yango (`yango_id` fait le
     * rapprochement). Une ligne par véhicule : la réaffectation déplace
     * `driver_id`, l'historique d'affectation n'est pas conservé.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            // Référence du véhicule côté Yango : clé de rapprochement Fleet.
            $table->string('yango_id')->nullable()->unique();
            $table->string('plate_number', 32)->index();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('color')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('is_active')->default(true);
            // Dernier rapprochement réussi avec l'API Fleet. Les véhicules
            // peuvent être synchronisés indépendamment d'un conducteur (parc
            // non affecté), d'où un horodatage propre.
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
