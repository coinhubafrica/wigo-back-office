<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Règlement du challenge : un seul document par challenge, porté par la
     * ligne plutôt que par une table dédiée — il n'y a rien à collectionner,
     * et un remplacement écrase le précédent.
     *
     * `rules_document_disk` est stocké par ligne, comme pour la carte grise :
     * le règlement d'un challenge passé doit survivre à un changement de
     * `FILESYSTEM_DISK`.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->string('rules_document_disk')->nullable()->after('draw_pool_hash');
            $table->string('rules_document_path')->nullable()->after('rules_document_disk');
            $table->string('rules_document_name')->nullable()->after('rules_document_path');
            $table->string('rules_document_mime', 100)->nullable()->after('rules_document_name');
            $table->unsignedInteger('rules_document_size')->nullable()->after('rules_document_mime');
            $table->timestamp('rules_document_uploaded_at')->nullable()->after('rules_document_size');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropColumn([
                'rules_document_disk',
                'rules_document_path',
                'rules_document_name',
                'rules_document_mime',
                'rules_document_size',
                'rules_document_uploaded_at',
            ]);
        });
    }
};
