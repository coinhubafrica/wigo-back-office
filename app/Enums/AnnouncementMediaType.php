<?php

namespace App\Enums;

enum AnnouncementMediaType: string
{
    case Image = 'image';
    case Video = 'video';

    /**
     * Type déduit du type MIME du fichier téléversé : le back-office ne demande
     * plus de le saisir, le fichier le dit déjà.
     *
     * Le MIME est reniflé sur le contenu, là où l'extension n'est qu'un bout du
     * nom que l'on renomme. La règle de validation du formulaire borne déjà les
     * formats acceptés ; ici seule la famille `video/*` compte, tout le reste
     * est une image.
     */
    public static function fromMimeType(string $mimeType): self
    {
        return str_starts_with(strtolower($mimeType), 'video/')
            ? self::Video
            : self::Image;
    }
}
