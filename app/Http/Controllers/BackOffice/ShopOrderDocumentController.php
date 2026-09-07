<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\ShopOrderDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert au back-office une photo de la carte grise jointe à une commande. Le
 * fichier est sur un disque privé — une carte grise nomme une personne et un
 * véhicule —, l'accès est donc porté par la session et la permission du module
 * Commandes, comme l'écran qui l'affiche.
 *
 * Le disque est celui de la ligne, pas `FILESYSTEM_DISK` : la pièce d'une
 * commande passée doit survivre à un changement de configuration.
 */
class ShopOrderDocumentController extends Controller
{
    public function __invoke(string $document): StreamedResponse
    {
        /*
        | Le modèle est résolu ici, pas par liaison de route : la liaison
        | répondrait 404 sur un identifiant inconnu et 403 sur une pièce
        | interdite, et l'écart entre les deux dirait quels identifiants
        | existent. Même raisonnement que `MessageAttachmentController`.
        */
        $found = ShopOrderDocument::query()->find($document);

        abort_if($found === null, 403);

        $disk = Storage::disk($found->disk);

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists($found->path), 404);

        // `inline` : la photo s'ouvre dans l'onglet ; le nom d'origine sert au
        // téléchargement, jamais le chemin de stockage.
        return $disk->response($found->path, $found->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($found->original_name).'"',
        ]);
    }
}
