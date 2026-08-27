<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les rôles sont administrés dans le back-office (Paramètres) et non figés
     * dans le code : ils portent donc leur propre libellé affichable, plutôt que
     * de dépendre d'une énumération PHP.
     */
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table): void {
            $table->string('label')->nullable()->after('name');
            $table->string('description')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table): void {
            $table->dropColumn(['label', 'description']);
        });
    }
};
