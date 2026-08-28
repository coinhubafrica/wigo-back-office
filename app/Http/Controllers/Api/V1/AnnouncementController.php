<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Support\Scramble\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Lister les bannières de l'accueil
     *
     * Uniquement celles publiées : actives et, si elles ont une fenêtre de
     * diffusion, dans cette fenêtre. Triées par ordre d'affichage.
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page`
     * est plafonné à 50.
     */
    #[ApiResponse(AnnouncementResource::class, collection: true, paginated: true)]
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('order')
            // `order` n'est pas unique : le curseur a besoin d'une clé
            // départage stable, sinon des annonces peuvent être sautées.
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request));

        return $this->okApiResponse(AnnouncementResource::collection($announcements));
    }

    /**
     * Taille de page demandée, bornée à 50 comme annoncé au contrat.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 50));
    }
}
