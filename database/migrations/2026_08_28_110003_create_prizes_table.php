<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue des lots physiques proposés à l'étape « Prix » de l'assistant
     * (téléviseur, réfrigérateur…), administré depuis l'onglet Lots. Ni stock
     * ni valeur : un lot est un nom et une photo, rattachés à une tombola.
     */
    public function up(): void
    {
        Schema::create('prizes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('photo_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prizes');
    }
};
