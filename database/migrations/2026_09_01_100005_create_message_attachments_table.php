<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pièce jointe d'un message, déposée sur le disque privé et servie par URL
     * signée — jamais par son chemin, comme la photo de profil.
     *
     * `message_id` est nullable : le mobile téléverse d'abord, rattache
     * ensuite. Cela garde l'envoi du message en JSON, donc compatible avec
     * l'empreinte de corps de `EnsureIdempotentRequest`, et rend le
     * téléversement réessayable indépendamment du texte. Les orphelines sont
     * purgées quotidiennement.
     *
     * `disk` est stocké par ligne, à la différence des annonces : une pièce
     * jointe est la trace d'un échange et doit survivre à un changement de
     * `FILESYSTEM_DISK`.
     *
     * v1 : images seulement. Aucun antivirus n'existe dans la chaîne, et un
     * agent ouvrant un PDF déposé par un tiers sur un outil interne est un
     * risque qu'on ne sait pas encore couvrir.
     */
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->foreignUlid('uploaded_by_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignUlid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
