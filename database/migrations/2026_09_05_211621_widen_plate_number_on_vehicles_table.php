<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `plate_number` tenait en 32 caractères, ce qui couvre une plaque
     * d'immatriculation mais pas ce que Yango range dans le même champ : les
     * véhicules de course (`COURIER…`) portent un jeton synthétique de
     * quarante caractères et plus. L'insertion tombait sur « Data too long »,
     * et la passe parc s'arrêtait au premier de ces véhicules.
     *
     * On élargit plutôt que de tronquer : la valeur fait foi côté Yango, et
     * une plaque coupée ne se rapproche plus de rien.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('plate_number', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('plate_number', 32)->change();
        });
    }
};
