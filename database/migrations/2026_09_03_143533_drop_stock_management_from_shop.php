<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrait de la gestion de stock : la boutique n'est plus qu'un catalogue
     * de références et de prix.
     *
     * `stock_quantity`, `low_stock_threshold` et la table `stock_movements`
     * disparaissent. Le statut à trois valeurs (`active`, `out_of_stock`,
     * `backorder`) — dont deux ne décrivaient qu'un état de stock — laisse
     * place au booléen `is_active` : l'agent ouvre ou ferme une référence à la
     * commande, rien n'est recalculé.
     */
    public function up(): void
    {
        Schema::dropIfExists('stock_movements');

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index()->after('photo_url');
        });

        // Une pièce « sur commande » restait commandable côté back-office :
        // seule la rupture ferme la référence.
        DB::table('products')->where('status', 'out_of_stock')->update(['is_active' => false]);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['stock_quantity', 'low_stock_threshold', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->string('status', 20)->default('active')->index();
        });

        DB::table('products')->where('is_active', false)->update(['status' => 'out_of_stock']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('shop_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 20)->index();
            $table->integer('quantity');
            $table->string('reason')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();
        });
    }
};
