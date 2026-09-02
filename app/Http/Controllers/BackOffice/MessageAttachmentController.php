<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert une pièce jointe du fil au back-office. Le fichier est sur un disque
 * privé — une photo de carte grise n'a rien à faire derrière une URL
 * devinable —, l'accès est donc porté par la session et la permission du
 * module Support, comme l'écran qui l'affiche.
 *
 * Le disque est celui de la ligne, pas `FILESYSTEM_DISK` : un échange doit
 * survivre à un changement de configuration.
 */
class MessageAttachmentController extends Controller
{
    public function __invoke(MessageAttachment $attachment): StreamedResponse
    {
        // Une pièce jamais rattachée n'appartient à aucun fil : rien à montrer.
        abort_if($attachment->isOrphan(), 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        // `inline` : une image ou un PDF s'ouvre dans l'onglet ; le nom
        // d'origine sert au téléchargement, jamais le chemin de stockage.
        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
        ]);
    }
}
