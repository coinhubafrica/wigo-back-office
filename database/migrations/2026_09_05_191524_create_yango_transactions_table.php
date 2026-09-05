<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grand livre du parc côté Yango (`/v2/parks/transactions/list`).
     *
     * À ne pas confondre avec `transactions`, qui porte l'argent *local* : une
     * recharge Wave, un paiement de commande, une cotisation. Celle-ci est une
     * copie de lecture de ce que Yango comptabilise pour le parc — les deux se
     * rapprochent, elles ne fusionnent pas.
     *
     * `driver_id` est **nullable**, contrairement à `yango_orders` : toutes les
     * écritures du parc ne visent pas un conducteur, et une ligne sans
     * conducteur reste comptable. Elle l'est aussi quand le conducteur n'a pas
     * encore de ligne locale — la synchronisation du parc l'adoptera plus tard.
     *
     * `amount` est un décimal et non un entier : Yango rend une chaîne à
     * quatre décimales (« 12345.1434 »). La passer par un flottant perdrait
     * des centimes sur les gros montants.
     */
    public function up(): void
    {
        Schema::create('yango_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();

            // Référence du mouvement côté Yango : clé de rapprochement, et
            // seule garantie qu'une passe rejouée n'écrit pas deux fois.
            $table->string('yango_id')->unique();

            $table->string('category_id')->nullable()->index();
            // Libellé de catégorie, rendu dans la langue demandée par
            // `Accept-Language` : conservé tel quel, jamais traduit ici.
            $table->string('category_name')->nullable();

            $table->decimal('amount', 20, 4);
            $table->string('currency', 3)->default('XOF');
            $table->string('description')->nullable();

            // Course à laquelle le mouvement se rattache, quand il s'en
            // rattache à une. Chaîne libre : c'est l'identifiant Yango, pas
            // une clé étrangère vers `yango_orders`, qui peut ne pas encore
            // porter cette course.
            $table->string('yango_order_id')->nullable()->index();

            $table->timestamp('event_at')->index();

            // Réponse brute : rien n'est perdu si un besoin futur réclame un
            // champ non promu en colonne (`created_by`, `event_id`).
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yango_transactions');
    }
};
