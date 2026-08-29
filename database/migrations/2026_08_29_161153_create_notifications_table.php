<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifications adressées aux conducteurs, par le système de notifications
     * de Laravel (`Notifiable`, `DatabaseNotification`).
     *
     * La ligne est écrite AVANT tout envoi push (règle du cahier des charges) :
     * l'écran « Notifications » du mobile lit cette table, le push n'est qu'un
     * réveil. Ajouter un canal (FCM, SMS, WhatsApp) se fera dans le `via()` des
     * classes de notification, sans toucher au schéma.
     *
     * `ulidMorphs` et non `morphs` : le générateur de Laravel pose un
     * `notifiable_id` numérique, or `drivers` est en ULID — aucune
     * notification ne pourrait viser un conducteur.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
