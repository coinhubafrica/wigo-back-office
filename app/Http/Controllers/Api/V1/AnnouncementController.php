<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnnouncementController extends Controller
{
    /**
     * Lister les bannières de l'accueil
     *
     * Uniquement celles publiées : actives et, si elles ont une fenêtre de
     * diffusion, dans cette fenêtre. Triées par ordre d'affichage.
     */
    public function index(): AnonymousResourceCollection
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
            ->get();

        return AnnouncementResource::collection($announcements);
    }
}
