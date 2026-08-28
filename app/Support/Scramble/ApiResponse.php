<?php

namespace App\Support\Scramble;

use Attribute;

/**
 * Déclare le contenu de `data` pour une méthode de contrôleur qui répond via
 * le trait `ApiResponses`.
 *
 * Scramble déduit le contrat du code, mais il ne peut pas traverser le trait :
 * il verrait le type interne du trait, pas la ressource réellement renvoyée.
 * Cet attribut rend l'intention explicite, et
 * `App\Support\Scramble\WrapApiEnvelope` la transforme en schéma
 * `{message, data, meta?, links?}`.
 *
 * Exemples :
 *   #[ApiResponse(DriverResource::class)]
 *   #[ApiResponse(AnnouncementResource::class, collection: true, paginated: true)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiResponse
{
    /**
     * @param  class-string|null  $resource  Ressource portée par `data`, ou
     *                                       null si la charge utile est décrite
     *                                       à la main par `@response`.
     * @param  bool  $collection  `data` est une liste de cette ressource.
     * @param  bool  $paginated  La réponse porte `meta` et `links`.
     */
    public function __construct(
        public ?string $resource = null,
        public bool $collection = false,
        public bool $paginated = false,
    ) {}
}
