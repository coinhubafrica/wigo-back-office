<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert l'image d'un envoi groupé au back-office. Le fichier est sur un disque
 * privé — une campagne peut illustrer une situation nominative —, l'accès est
 * donc porté par la session et la permission du module Campagnes, comme
 * l'écran qui l'affiche.
 *
 * Le disque est celui de la ligne, pas `FILESYSTEM_DISK` : la trace d'un envoi
 * doit survivre à un changement de configuration.
 */
class CampaignImageController extends Controller
{
    public function __invoke(string $campaign): StreamedResponse
    {
        /*
        | Résolution à la main, sans liaison de route : la liaison répondrait
        | 404 sur un identifiant inconnu et 403 sur une campagne sans image, et
        | l'écart entre les deux dirait quels identifiants existent. Tout ce
        | qui n'est pas servi répond 403 — même raisonnement que
        | `MessageAttachmentController` et `DriverPhotoController`.
        */
        $found = Campaign::query()->find($campaign);

        abort_if($found === null || ! $found->hasImage(), 403);

        $disk = Storage::disk($found->image_disk);

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists($found->image_path), 404);

        return $disk->response($found->image_path, $found->image_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $found->image_name).'"',
        ]);
    }
}
