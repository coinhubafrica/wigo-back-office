<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligne les actions journalisées sur le nouveau nom de l'intégration.
 *
 * Le journal est en ajout seul, et on y touche pourtant : `AuditAction` est
 * un enum adossé à ces chaînes, si bien qu'une ligne restée en
 * `recharge.fleet_failed` ne se résoudrait plus et ferait tomber l'écran
 * Audit. Réécrire deux valeurs est le moindre mal face à un journal
 * illisible ; ni l'auteur, ni la date, ni la charge utile ne bougent.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const RENAMES = [
        'recharge.fleet_failed' => 'recharge.yango_failed',
        'settings.fleet_updated' => 'settings.yango_updated',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('audit_logs')->where('action', $from)->update(['action' => $to]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('audit_logs')->where('action', $to)->update(['action' => $from]);
        }
    }
};
