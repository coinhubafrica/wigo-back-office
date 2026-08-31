<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert la photo de profil d'un conducteur à la fiche du back-office. Le
 * fichier est sur le disque privé — un portrait n'a rien à faire derrière une
 * URL publique devinable —, l'accès est donc porté par la session et la
 * permission du module Conducteurs, comme la fiche elle-même.
 */
class DriverPhotoController extends Controller
{
    public function __invoke(Driver $driver): StreamedResponse
    {
        abort_if($driver->photo_url === null, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($driver->photo_url), 404);

        return $disk->response($driver->photo_url);
    }
}
