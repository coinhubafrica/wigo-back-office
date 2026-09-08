<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `drivers.status` adopte le vocabulaire de Yango.
 *
 * `active`/`suspended`/`dormant` devient `working`/`not_working`/`fired` : le
 * parc est tenu chez Yango, et la synchronisation écrit désormais cette
 * colonne à chaque passe. La suspension côté back-office disparaît avec
 * `suspension_reason` — couper un conducteur se décide sur la plateforme
 * Yango et nous revient par `fired`.
 *
 * Les conducteurs suspendus localement partent en `not_working` et non en
 * `fired` : rien ne dit que Yango les a radiés, et la première passe de
 * synchronisation tranchera. Mieux vaut un statut trop tendre une heure
 * qu'une radiation inventée.
 */
return new class extends Migration
{
    /**
     * Ancien vocabulaire vers le nouveau.
     *
     * @var array<string, string>
     */
    private const FORWARD = [
        'active' => 'working',
        'suspended' => 'not_working',
        'dormant' => 'not_working',
    ];

    /**
     * Retour : `not_working` retombe sur `dormant`, la suspension locale
     * n'étant pas reconstituable une fois son motif effacé.
     *
     * @var array<string, string>
     */
    private const BACKWARD = [
        'working' => 'active',
        'not_working' => 'dormant',
        'fired' => 'suspended',
    ];

    public function up(): void
    {
        $this->remapStatuses(self::FORWARD);

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropColumn('suspension_reason');
            $table->string('status', 20)->default('not_working')->change();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('suspension_reason')->nullable()->after('status');
            $table->string('status', 20)->default('active')->change();
        });

        $this->remapStatuses(self::BACKWARD);
    }

    /**
     * Réécrit `drivers.status` et les segments de campagne qui le citent.
     *
     * Les segments sont du JSON libre (`campaigns.segment`) : un statut resté
     * dans l'ancien vocabulaire serait écarté par `DriverStatus::tryFrom()` et
     * la campagne, privée de sa clause de statut, s'adresserait alors à tout
     * le parc. Une audience qui s'élargit en silence est pire qu'une erreur.
     *
     * @param  array<string, string>  $map
     */
    private function remapStatuses(array $map): void
    {
        foreach ($map as $from => $to) {
            DB::table('drivers')->where('status', $from)->update(['status' => $to]);
        }

        $campaigns = DB::table('campaigns')
            ->whereNotNull('segment')
            ->get(['id', 'segment']);

        foreach ($campaigns as $campaign) {
            $segment = json_decode((string) $campaign->segment, true);

            if (! is_array($segment) || ! is_array($segment['status'] ?? null)) {
                continue;
            }

            $remapped = array_values(array_unique(array_map(
                fn (mixed $status): string => $map[(string) $status] ?? (string) $status,
                $segment['status'],
            )));

            if ($remapped === $segment['status']) {
                continue;
            }

            $segment['status'] = $remapped;

            DB::table('campaigns')
                ->where('id', $campaign->id)
                ->update(['segment' => json_encode($segment)]);
        }
    }
};
