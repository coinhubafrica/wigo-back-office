<?php

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Publie l'en-tête `Idempotency-Key` sur les écritures qui l'exigent.
 *
 * `App\Http\Middleware\EnsureIdempotentRequest` rejette sans lui une requête
 * en 422, mais un middleware est invisible à Scramble : le contrat ne
 * mentionnait ni l'en-tête ni le 409 du rejeu, et le bouton d'essai de
 * `/docs/api` ne pouvait donc pas construire une requête valide.
 *
 * L'extension lit la liste des middlewares de la route plutôt qu'une liste
 * d'URL en dur : une nouvelle écriture protégée par `idempotency` est
 * documentée sans rien changer ici.
 */
class DocumentIdempotencyKey extends OperationExtension
{
    private const MIDDLEWARE = 'idempotency';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array(self::MIDDLEWARE, $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $operation->addParameters([
            Parameter::make('Idempotency-Key', 'header')
                ->required(true)
                ->setSchema(Schema::fromType((new StringType)->format('uuid')))
                ->example('9f1c2b7e-4d3a-4f8b-9c2e-1a5d6b7c8e90')
                ->description(
                    'UUID propre à la requête. Renvoyer deux fois le même appel avec la '
                    .'même clé ne crée qu\'une ressource : la réponse enregistrée est '
                    .'rendue telle quelle. La clé expire au bout de 24 h.',
                ),
        ]);

        $operation->addResponse(
            Response::make(409)
                ->description('La clé a déjà servi pour une requête au corps différent ; rien n\'est créé.')
                ->setContent('application/json', Schema::fromType($this->errorEnvelope())),
        );
    }

    /**
     * L'enveloppe d'erreur `{message, errors}` du gestionnaire d'exceptions.
     */
    private function errorEnvelope(): ObjectType
    {
        $envelope = new ObjectType;

        $envelope->addProperty('message', (new StringType)->setDescription(
            'Message prêt à afficher, en français.',
        ));
        $envelope->addProperty('errors', (new ObjectType)->setDescription(
            'Erreurs par champ ; ici la clé `Idempotency-Key`.',
        ));

        $envelope->addRequired(['message', 'errors']);

        return $envelope;
    }
}
