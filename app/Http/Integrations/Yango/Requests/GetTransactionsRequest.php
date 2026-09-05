<?php

namespace App\Http\Integrations\Yango\Requests;

use Carbon\CarbonInterface;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Page du grand livre du parc sur une fenêtre de dates.
 *
 * Même pagination par curseur que les courses, et même absence de `total`.
 * Piège propre à cet endpoint : `limit` vaut **40 par défaut** côté Yango
 * alors qu'il accepte jusqu'à 1000. Le laisser implicite ferait vingt-cinq
 * fois trop d'appels pour la même fenêtre — il est donc toujours transmis.
 *
 * À ne pas confondre avec `CreateDriverTransactionRequest`, qui écrit un
 * mouvement : celle-ci ne fait que lire ceux du parc.
 */
class GetTransactionsRequest extends Request implements HasBody
{
    use HasJsonBody;

    /** Plafond imposé par Yango sur cet endpoint. */
    public const MAX_LIMIT = 1000;

    /**
     * Taille de page réellement demandée, en deçà du plafond.
     *
     * Le plafond dit ce que Yango accepte, pas ce qu'il supporte : une passe
     * du grand livre qui réclame le maximum à chaque page se fait refuser en 429
     * avant d'avoir fini. Observé contre l'API vivante — le plafond reste la
     * borne, celle-ci est le régime de croisière.
     */
    public const DEFAULT_LIMIT = 500;

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
        return '/v2/parks/transactions/list';
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
                    'transaction' => [
                        'event_at' => [
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
