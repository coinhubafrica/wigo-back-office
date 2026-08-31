<?php

namespace App\Enums;

/**
 * Cycle de vie d'une diffusion. `Sending` est l'état pendant lequel le job
 * matérialise les destinataires — il borne la reprise en cas d'échec.
 */
enum BroadcastStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Scheduled => 'Programmée',
            self::Sending => 'Envoi en cours',
            self::Sent => 'Envoyée',
            self::Failed => 'Échouée',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-zinc-100 text-zinc-500',
            self::Scheduled => 'bg-warn-bg text-warn-text',
            self::Sending => 'bg-warn-bg text-warn-text',
            self::Sent => 'bg-ok-bg text-ok-text',
            self::Failed => 'bg-err-bg text-err-text',
        };
    }
}
