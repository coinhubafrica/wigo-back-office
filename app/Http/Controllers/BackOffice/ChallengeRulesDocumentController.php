<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert au back-office le règlement joint à un challenge. Le fichier vit sur un
 * disque privé — le contrat mobile le sert par URL signée —, l'accès est donc
 * porté par la session et la permission du module Challenges, comme l'écran
 * qui l'affiche.
 *
 * Le disque est celui de la ligne, pas `FILESYSTEM_DISK` : le règlement d'un
 * challenge passé doit survivre à un changement de configuration.
 */
class ChallengeRulesDocumentController extends Controller
{
    public function __invoke(string $challenge): StreamedResponse
    {
        /*
        | Le modèle est résolu ici, pas par liaison de route : la liaison
        | répondrait 404 sur un identifiant inconnu et 403 sur un règlement
        | interdit, et l'écart entre les deux dirait quels challenges
        | existent. Même raisonnement que `ShopOrderDocumentController`.
        */
        $found = Challenge::query()->find($challenge);

        abort_if($found === null || ! $found->hasRulesDocument(), 403);

        $disk = Storage::disk((string) $found->rules_document_disk);

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists((string) $found->rules_document_path), 404);

        // `inline` : le règlement s'ouvre dans l'onglet ; le nom d'origine sert
        // au téléchargement, jamais le chemin de stockage.
        $name = (string) $found->rules_document_name;

        return $disk->response((string) $found->rules_document_path, $name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }
}
