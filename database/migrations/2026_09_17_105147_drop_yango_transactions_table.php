<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire le grand livre Yango, qui ne servait à personne.
 *
 * La copie de lecture des mouvements du parc n'avait aucun lecteur hors de son
 * propre écran : ni le journal du conducteur, ni la CNPS, ni la réconciliation
 * Wave ne la joignaient. Sa passe était par ailleurs la seule à échouer encore
 * en production, et sa requête de couverture ne terminait pas.
 *
 * À ne pas confondre avec `transactions`, qui porte l'argent **local** — une
 * recharge Wave, un paiement de commande, une cotisation. Celle-là reste.
 *
 * L'ordre compte. Les traces de passes partent **avant** la table :
 * `yango_sync_runs.kind` est une chaîne castée en énumération, et le cas
 * `Transactions` disparaît avec cette migration. Une ligne laissée derrière
 * lèverait un `ValueError` au premier rendu de l'écran, pas au déploiement —
 * c'est-à-dire au pire moment.
 *
 * `down()` ne restaure rien : la table est supprimée sans export, décision
 * prise en connaissance du volume (812 899 lignes). La réacquérir demanderait
 * une boucle de curseur bridée par journée, plafonnée à 31 jours par relance.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('yango_sync_runs')->where('kind', 'transactions')->delete();

        Schema::dropIfExists('yango_transactions');
    }

    public function down(): void
    {
        // Sans retour : ni la table, ni les traces effacées ne se
        // reconstituent d'ici. Le rattrapage passerait par l'API Yango.
    }
};
