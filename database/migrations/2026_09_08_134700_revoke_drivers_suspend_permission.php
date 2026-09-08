<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire le droit `drivers.suspend`, devenu sans objet.
 *
 * La suspension d'un conducteur se décide sur la plateforme Yango et nous
 * revient par le `work_status` de la synchronisation ; le back-office n'a plus
 * de geste à garder. La ligne est supprimée plutôt que laissée orpheline : un
 * droit qui ne commande plus rien finit par être accordé « au cas où ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        $ids = DB::table($tables['permissions'])
            ->where('name', 'drivers.suspend')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table($tables['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['model_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['permissions'])->whereIn('id', $ids)->delete();
    }

    /**
     * Irréversible : le droit ne correspond plus à aucun geste, le recréer
     * n'apprendrait à personne qui le détenait.
     */
    public function down(): void {}
};
