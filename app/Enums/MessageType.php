<?php

namespace App\Enums;

/**
 * Nature du contenu d'un message — pas de son émetteur : celui-ci se lit sur
 * la relation `sender` (absente pour un message système).
 */
enum MessageType: string
{
    case Text = 'text';
    case Attachment = 'attachment';
    case System = 'system';
}
