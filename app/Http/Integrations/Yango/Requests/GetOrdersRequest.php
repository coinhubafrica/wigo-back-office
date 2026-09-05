<?php

namespace App\Http\Integrations\Yango\Requests;

use Carbon\CarbonInterface;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Page de courses du parc sur une fenêtre de dates.
 *
 * Yango exige ici `booked_at` ou `ended_at` : sans fenêtre, l'appel est
 * refusé. On filtre sur `ended_at` — c'est la fin de course qui décide du jour
 * d'activité, donc des tickets de challenge.
 *
 * Pagination par curseur, pas par décalage : la réponse ne porte pas de
 * `total`, et le curseur du premier appel n'existe pas. Yango impose au
 * curseur une longueur minimale de 1, si bien qu'une chaîne vide serait
 * refusée — la clé est donc omise tant qu'on n'en a pas reçu un.
 *
 * `limit` plafonne à 500 sur cet endpoint, pas à 1000 comme sur le parc.
 */
class GetOrdersRequest extends Request implements HasBody
{
    use HasJsonBody;

    /** Plafond imposé par Yango sur cet endpoint. */
    public const MAX_LIMIT = 500;

    /**
     * Taille de page réellement demandée, en deçà du plafond.
     *
     * Le plafond dit ce que Yango accepte, pas ce qu'il supporte : une passe
     * des courses qui réclame le maximum à chaque page se fait refuser en 429
     * avant d'avoir fini. Observé contre l'API vivante — le plafond reste la
     * borne, celle-ci est le régime de croisière.
     */
    public const DEFAULT_LIMIT = 250;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected CarbonInterface $from,
        protected CarbonInterface $to,
        protected int $limit = self::DEFAULT_LIMIT,
        protected ?string $cursor = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v1/parks/orders/list';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBody(): array
    {
        $body = [
            'query' => [
                'park' => [
                    'id' => $this->parkId,
                    'order' => [
                        'ended_at' => [
                            'from' => $this->from->toIso8601String(),
                            'to' => $this->to->toIso8601String(),
                        ],
                    ],
                ],
            ],
            'limit' => min($this->limit, self::MAX_LIMIT),
        ];

        if ($this->cursor !== null && $this->cursor !== '') {
            $body['cursor'] = $this->cursor;
        }

        return $body;
    }
}
