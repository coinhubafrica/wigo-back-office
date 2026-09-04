<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert la photo de profil d'un conducteur au back-office. Le fichier est sur
 * le disque privé — un portrait n'a rien à faire derrière une URL publique
 * devinable —, l'accès est donc porté par la session et par la permission d'un
 * module qui montre des conducteurs.
 *
 * Deux modules l'affichent : la fiche du conducteur et le fil du support, dont
 * les avatars pointent ici. La route accepte donc l'une ou l'autre permission
 * (`module.drivers|module.support-requests`) : la restreindre aux seuls
 * Conducteurs cassait l'avatar d'un agent qui ne fait que du support.
 *
 * Il n'existe pas de rattachement d'un agent à un sous-ensemble de conducteurs
 * — la liste montre tout le parc à qui porte le module. Une garde « ce
 * conducteur est-il le vôtre ? » n'aurait donc rien à vérifier.
 */
class DriverPhotoController extends Controller
{
    public function __invoke(string $driver): StreamedResponse
    {
        /*
        | Résolution manuelle plutôt que liaison de route, pour la même raison
        | que sur les pièces jointes : un identifiant inconnu répondrait 404 là
        | où un accès refusé répond 403, et l'écart dirait quels conducteurs
        | existent. Un portrait absent répond comme un conducteur inconnu.
        */
        $found = Driver::query()->find($driver);

        abort_if($found === null || $found->photo_url === null, 403);

        $disk = Storage::disk('local');

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists($found->photo_url), 404);

        return $disk->response($found->photo_url);
    }
}
