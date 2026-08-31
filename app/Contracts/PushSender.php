<?php

namespace App\Contracts;

use App\Models\Driver;

/**
 * Envoi d'une notification push à l'application mobile.
 *
 * Les messages sont « data-only » : aucune bannière n'est composée côté
 * serveur, c'est Flutter qui décide de l'affichage. Le push n'est qu'un
 * réveil — le contenu qui fait foi est la ligne écrite dans `notifications`.
 */
interface PushSender
{
    /**
     * @param  array<string, string>  $data  Charge utile, valeurs en chaînes :
     *                                       FCM n'accepte rien d'autre.
     * @return bool `false` si le conducteur n'a pas de jeton exploitable.
     */
    public function send(Driver $driver, array $data): bool;
}
