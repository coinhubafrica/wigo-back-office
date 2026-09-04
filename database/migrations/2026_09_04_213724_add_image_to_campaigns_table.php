<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Image d'un envoi groupé, téléversée une fois et déposée dans chaque fil.
     *
     * Le fichier est stocké **une seule fois** : la campagne le porte, et
     * l'envoi crée une ligne `message_attachments` par conducteur, toutes sur
     * le même `path`. Cinq mille conducteurs ne font donc pas cinq mille
     * copies du même JPEG sur le disque.
     *
     * `image_disk` est stocké comme sur `message_attachments`, et pour la même
     * raison : la trace d'un envoi doit survivre à un changement de
     * `FILESYSTEM_DISK`. Le disque retenu à la composition est `local` — le
     * disque **privé**, racine `storage/app/private` — et non `public` : une
     * campagne peut illustrer une situation nominative, et rien ici n'a
     * besoin d'une URL devinable.
     *
     * Images seulement : aucun antivirus n'existe dans la chaîne, et la même
     * borne vaut déjà pour les pièces jointes du support.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('image_disk')->nullable()->after('deeplink');
            $table->string('image_path')->nullable()->after('image_disk');
            $table->string('image_name')->nullable()->after('image_path');
            $table->string('image_mime', 120)->nullable()->after('image_name');
            $table->unsignedInteger('image_size_bytes')->nullable()->after('image_mime');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'image_disk',
                'image_path',
                'image_name',
                'image_mime',
                'image_size_bytes',
            ]);
        });
    }
};
