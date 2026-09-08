<?php

namespace App\Http\Resources;

use App\Enums\HistoryKind;
use App\Enums\HistoryStatus;
use App\Models\ShopOrderItem;
use App\Services\Cnps\CnpsStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;

/**
 * Une ligne du fil d'activité, telle que l'application l'affiche.
 *
 * Classe simple, volontairement pas une `JsonResource` avec `@mixin` : la ligne
 * vient de la vue `driver_history`, qui n'est pas un modèle. Sa forme est
 * publiée dans `docs/api/paths/history.yaml`.
 *
 * Répartition assumée : la vue trie et chiffre, cette classe rédige. Le SQL ne
 * peut pas composer le sous-titre d'une commande sans multiplier les lignes de
 * son UNION.
 */
class HistoryEntryPayload
{
    /**
     * @param  stdClass  $row  ligne de la vue `driver_history` : `kind`, `ref`,
     *                         `occurred_at`, `amount`, `status_raw`, `source_key`
     * @param  Collection<string, EloquentCollection<int, ShopOrderItem>>  $orderItems  pièces de la page, par commande
     * @return array<string, mixed>
     */
    public static function build(stdClass $row, Collection $orderItems, CnpsStatementService $cnps): array
    {
        $kind = HistoryKind::from((string) $row->kind);
        $occurredAt = CarbonImmutable::parse($row->occurred_at);
        $status = HistoryStatus::fromSource($kind, $row->status_raw === null ? null : (string) $row->status_raw);

        return [
            'id' => (string) $row->id,
            /**
             * @var 'recharge'|'order'|'cnps'|'ticket'|'bonus'
             */
            'kind' => $kind->value,
            /**
             * @example "CMD-2026-0114"
             */
            'ref' => $row->ref === null ? null : (string) $row->ref,
            'label' => $kind->label(),
            'sublabel' => self::sublabelFor($kind, $row, $occurredAt, $status, $orderItems, $cnps),
            'amount' => [
                /**
                 * Magnitude, toujours positive : le sens vit dans `sign`.
                 *
                 * @example 60000
                 */
                'value' => (int) $row->amount,
                /**
                 * -1 sortie (rouge), +1 entrée (verte), 0 sans mouvement dans
                 * notre portefeuille (noir).
                 *
                 * @var -1|0|1
                 */
                'sign' => self::sign($kind, $status),
                /**
                 * @var 'XOF'|'ticket'
                 */
                'unit' => $kind->unit(),
            ],
            /**
             * @var 'pending'|'settled'|'failed'|'cancelled'
             */
            'status' => $status->value,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];
    }

    /**
     * Une commande annulée ne pèse plus rien : son montant s'affiche en noir,
     * pas en rouge. Le reste suit la famille.
     *
     * @return -1|0|1
     */
    private static function sign(HistoryKind $kind, HistoryStatus $status): int
    {
        if ($status === HistoryStatus::Cancelled) {
            return 0;
        }

        return match ($kind->sign()) {
            -1 => -1,
            0 => 0,
            default => 1,
        };
    }

    /**
     * Ligne grise : ce que la famille a de plus parlant, puis la date.
     *
     * @param  Collection<string, Collection<int, ShopOrderItem>>  $orderItems
     */
    /**
     * @param  Collection<string, EloquentCollection<int, ShopOrderItem>>  $orderItems
     */
    private static function sublabelFor(
        HistoryKind $kind,
        stdClass $row,
        CarbonImmutable $occurredAt,
        HistoryStatus $status,
        Collection $orderItems,
        CnpsStatementService $cnps,
    ): string {
        // Les tickets et les cotisations n'ont qu'une date en base (colonnes
        // `date`) : afficher « · 00:00 » afficherait une heure qu'on ne connaît
        // pas. Les mouvements d'argent, eux, sont horodatés à la seconde.
        $stamp = $kind->isDatedToTheDay()
            ? $occurredAt->settings(['locale' => 'fr'])->translatedFormat('j M')
            : $occurredAt->settings(['locale' => 'fr'])->translatedFormat('j M · H:i');

        $head = match ($kind) {
            // Les pièces commandées, dans l'ordre où la boutique les nomme.
            HistoryKind::Order => self::orderParts($row, $orderItems),
            // Le mois couvert, pas celui du versement : c'est ce que le
            // conducteur reconnaît avoir déclaré.
            HistoryKind::Cnps => $row->source_key === null ? null : $cnps->labelFor((string) $row->source_key),
            HistoryKind::Ticket => self::tickets((int) $row->amount),
            HistoryKind::Recharge => $row->source_key === null ? null : (string) $row->source_key,
            HistoryKind::Bonus => 'Gain de challenge',
        };

        $parts = array_filter([$head, $stamp]);

        // Une commande annulée le dit, sinon le montant noir serait sans raison.
        if ($status === HistoryStatus::Cancelled) {
            $parts[] = 'Annulée';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  Collection<string, EloquentCollection<int, ShopOrderItem>>  $orderItems
     */
    private static function orderParts(stdClass $row, Collection $orderItems): ?string
    {
        /** @var EloquentCollection<int, ShopOrderItem>|null $items */
        $items = $row->source_key === null ? null : $orderItems->get((string) $row->source_key);

        if ($items === null || $items->isEmpty()) {
            return null;
        }

        return $items
            ->map(fn (ShopOrderItem $item): string => $item->product_name)
            ->join(', ');
    }

    /**
     * Les tickets d'un même jour sont agrégés par la vue : la ligne porte leur
     * compte, pas un ticket.
     */
    private static function tickets(int $count): string
    {
        return $count.' '.Str::plural('ticket', $count).' gagné'.($count > 1 ? 's' : '');
    }
}
