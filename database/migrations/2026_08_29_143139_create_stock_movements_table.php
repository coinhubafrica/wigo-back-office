<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mouvement de stock : approvisionnement du magasinier (`in`), sortie liée
     * à une commande (`out`), correction manuelle (`adjustment`).
     *
     * `quantity` est signée — négative pour une sortie — pour que la somme des
     * mouvements d'une pièce redonne son stock. `user_id` est nul quand le
     * mouvement vient d'une commande mobile et non d'un agent.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('shop_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 20)->index();
            $table->integer('quantity');
            $table->string('reason')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
