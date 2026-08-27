<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les utilisateurs du back-office sont portés par la table `users`
     * (guard `web`, session) — il n'y a pas de table dédiée malgré le MCD. On y
     * ajoute les champs attendus par le back-office.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone', 20)->nullable()->after('email');

            // Un utilisateur désactivé conserve son compte mais ne peut plus se
            // connecter (les comptes sont créés par la direction, jamais
            // supprimés, pour préserver les références historiques).
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'is_active',
                'last_login_at',
            ]);
        });
    }
};
