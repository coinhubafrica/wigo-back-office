<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ligne de commande. `product_name`, `unit_price` et `line_total` figent
     * la pièce au moment de l'achat (le MCD les dénormalise exprès) : le prix
     * du catalogue peut changer, une commande passée ne bouge plus.
     * `product_id` peut donc devenir nul si la référence disparaît.
     */
    public function up(): void
    {
        Schema::create('shop_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_items');
    }
};
