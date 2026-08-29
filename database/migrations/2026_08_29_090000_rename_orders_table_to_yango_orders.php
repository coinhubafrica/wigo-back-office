<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `orders` porte les courses Yango terminées (MCD : `trip`), nommées ainsi
     * pour coller au vocabulaire de l'API Fleet (`/v1/parks/orders/list`). Le
     * module Boutique introduit ses propres commandes (`shop_orders`) : les
     * deux notions cohabiteraient sous le même mot. La table des courses est
     * donc préfixée pour lever toute ambiguïté.
     */
    public function up(): void
    {
        Schema::rename('orders', 'yango_orders');
    }

    public function down(): void
    {
        Schema::rename('yango_orders', 'orders');
    }
};
