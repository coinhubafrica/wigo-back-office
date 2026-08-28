<?php

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType as InferredObjectType;
use ReflectionMethod;

/**
 * Décrit l'enveloppe de l'API mobile dans le contrat publié.
 *
 * Les contrôleurs répondent via le trait `ApiResponses`, qui construit
 * `{message, data}` — plus `meta`/`links` quand les données sont paginées.
 * Scramble déduit le contrat du code, mais il ne sait pas traverser le trait :
 * il documenterait un type interne. Chaque méthode déclare donc sa charge
 * utile avec `#[ApiResponse(...)]`, et cette extension la transforme en schéma
 * enveloppé — la ressource elle-même restant résolue par les extensions
 * natives de Scramble, ce qui préserve les schémas nommés et les enums.
 */
class WrapApiEnvelope extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $declaration = $this->declaration($routeInfo);

        if ($declaration === null) {
            return;
        }

        $data = $this->dataSchema($declaration);

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $code = $response->code;

            // Les erreurs sont mises en forme par le gestionnaire
            // d'exceptions ; un 204 n'a pas de corps.
            if (! is_int($code) || $code < 200 || $code >= 300 || $code === 204) {
                continue;
            }

            $response->setContent(
                'application/json',
                Schema::fromType($this->envelope($data, $declaration->paginated)),
            );
        }
    }

    /**
     * L'enveloppe : `message` et `data` toujours présents, `meta`/`links`
     * uniquement pour une réponse paginée.
     */
    private function envelope(mixed $data, bool $paginated): ObjectType
    {
        $envelope = new ObjectType;

        $envelope->addProperty('message', (new StringType)->setDescription(
            'Message prêt à afficher, en français.',
        ));
        $envelope->addProperty('data', $data);

        if ($paginated) {
            $envelope->addProperty('meta', (new ObjectType)->setDescription(
                'Pagination par curseur : `per_page`, `next_cursor` (`null` sur la dernière page).',
            ));
            $envelope->addProperty('links', (new ObjectType)->setDescription(
                'Liens de pagination (`next`, `prev`) lorsqu\'ils existent.',
            ));
        }

        $envelope->addRequired(['message', 'data']);

        return $envelope;
    }

    /**
     * Schéma de `data` : la ressource résolue par Scramble, éventuellement en
     * liste. Sans ressource déclarée, on laisse un objet libre plutôt que de
     * publier un type faux.
     */
    private function dataSchema(ApiResponse $declaration): mixed
    {
        if ($declaration->resource === null) {
            return new ObjectType;
        }

        $resource = $this->openApiTransformer->transform(
            new InferredObjectType($declaration->resource),
        );

        if ($resource instanceof UnknownType) {
            return new ObjectType;
        }

        if (! $declaration->collection) {
            return $resource;
        }

        return (new ArrayType)->setItems($resource);
    }

    /**
     * L'attribut porté par la méthode de contrôleur, s'il y en a un.
     */
    private function declaration(RouteInfo $routeInfo): ?ApiResponse
    {
        $class = $routeInfo->className();
        $method = $routeInfo->methodName();

        if ($class === null || $method === null || ! method_exists($class, $method)) {
            return null;
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(ApiResponse::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
