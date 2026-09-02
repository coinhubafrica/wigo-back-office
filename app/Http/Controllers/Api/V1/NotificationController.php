<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * L'écran « Notifications » de l'application.
 *
 * Les notifications sont écrites en base d'abord ; le push n'est qu'un réveil.
 * C'est cette table que l'application lit, y compris après coup.
 */
class NotificationController extends Controller
{
    use ResolvesDriver;

    /**
     * Notifications du conducteur
     *
     * Les plus récentes d'abord. La charge utile de chaque notification est
     * aplatie à la racine (`type`, `category`, `title`, `body`, `deeplink`).
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page`
     * est plafonné à 50.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->driver($request)
            ->notifications()
            ->orderByDesc('created_at')
            // `created_at` n'est pas unique : le curseur a besoin d'une clé de
            // départage stable, sinon des lignes peuvent être sautées.
            ->orderByDesc('id')
            ->cursorPaginate(max(1, min($request->integer('per_page', 20), 50)));

        return $this->okApiResponse(NotificationResource::collection($notifications));
    }

    /**
     * Marquer une notification comme lue
     *
     * Répond 404 pour une notification qui n'appartient pas au conducteur :
     * rien ne doit fuir d'un compte à l'autre, pas même son existence.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        /** @var DatabaseNotification|null $found */
        $found = $this->driver($request)->notifications()->whereKey($notification)->first();

        abort_if($found === null, 404);

        $found->markAsRead();

        return $this->okApiResponse(new NotificationResource($found->refresh()));
    }

    /**
     * Tout marquer comme lu
     *
     * @response array{message: string, data: array{unread_count: int}}
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->driver($request)->unreadNotifications()->update(['read_at' => now()]);

        return $this->okApiResponse(['unread_count' => 0]);
    }
}
