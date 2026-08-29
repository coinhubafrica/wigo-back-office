<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commande passée à la boutique par un conducteur. Préfixée `shop_` :
     * `yango_orders` porte déjà les courses Yango, deux notions distinctes que
     * le mot « commande » confondrait.
     *
     * `pickup_code` n'existe que pour un retrait en agence : six chiffres
     * vérifiés au comptoir. Chaque étape du cycle de vie horodate sa propre
     * colonne plutôt que de se déduire d'un journal : le back-office affiche
     * ces dates telles quelles.
     *
     * Hors périmètre : facturation (`invoice_number` / `invoice_url` du MCD)
     * et panier serveur (`carts`) — le mobile poste ses lignes directement.
     */
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('status', 20)->default('ordered')->index();
            $table->string('fulfilment_mode', 20);
            $table->string('pickup_code', 6)->nullable();
            $table->unsignedInteger('total_amount');
            $table->timestamp('ordered_at');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
