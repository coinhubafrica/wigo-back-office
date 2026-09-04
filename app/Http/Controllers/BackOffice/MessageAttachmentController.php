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
    public function __invoke(string $attachment): StreamedResponse
    {
        /*
        | Le modèle est résolu ici, pas par liaison de route : la liaison
        | répondrait 404 sur un identifiant inconnu et 403 sur une pièce
        | interdite, et l'écart entre les deux dirait quels identifiants
        | existent. Tout ce qui n'est pas servi répond 403, sans distinguer
        | « n'existe pas » de « pas pour vous ».
        |
        | Même raisonnement que `Api\V1\SupportController::downloadAttachment`,
        | qui scope la pièce au fil du conducteur : ici la permission du module
        | borne l'accès, la pièce doit seulement appartenir à un fil réel.
        */
        $found = MessageAttachment::query()
            ->with('message')
            ->find($attachment);

        // Une pièce jamais rattachée n'appartient à aucun fil, et un message
        // supprimé ne laisse rien à montrer : dans les deux cas il n'y a pas de
        // conversation qui autorise la lecture.
        abort_if($found === null || $found->isOrphan() || $found->message === null, 403);

        $disk = Storage::disk($found->disk);

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists($found->path), 404);

        // `inline` : une image ou un PDF s'ouvre dans l'onglet ; le nom
        // d'origine sert au téléchargement, jamais le chemin de stockage.
        return $disk->response($found->path, $found->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($found->original_name).'"',
        ]);
    }
}
