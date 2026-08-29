<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modalité de réception d'une commande : retrait dans une agence
     * (`pickup_point_id`) ou livraison à une position (`latitude`/`longitude`).
     * Une commande, une livraison — d'où la contrainte d'unicité.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('pickup_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address_hint')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('operator_name')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
