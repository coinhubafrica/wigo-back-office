<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le fil unique et permanent d'un conducteur avec le support.
     *
     * Côté mobile il n'y a que ça : une conversation qui ne se termine jamais,
     * comme une discussion de messagerie. Les tickets (`support_requests`)
     * découpent ce même fil pour le back-office, et restent invisibles du
     * conducteur — résoudre un ticket ne ferme rien pour lui.
     *
     * Les colonnes dénormalisées (`last_message_*`, `driver_unread_count`)
     * existent pour que la liste et le badge ne lisent jamais `messages` :
     * elles sont tenues à jour dans `MessageService`, sous transaction.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_message_preview', 160)->nullable();

            // 'user' | 'driver' | null (message système) — valeurs de la
            // morph map, pas des noms de classes.
            $table->string('last_message_sender_type', 20)->nullable();

            $table->unsignedInteger('driver_unread_count')->default(0);
            $table->timestamp('driver_read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
