<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Resources\BroadcastResource;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Support\Scramble\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les diffusions reçues par le conducteur.
 *
 * En lecture seule : une diffusion ne se répond pas. L'application ouvre le
 * fil du support si le conducteur veut réagir, et l'agent voit alors de quelle
 * diffusion la conversation est partie.
 */
class BroadcastController extends Controller
{
    use ResolvesDriver;

    /**
     * Diffusions du conducteur
     *
     * Les plus récentes d'abord. Seules celles qui lui ont été adressées
     * apparaissent : l'audience est figée à l'envoi.
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page`
     * est plafonné à 50.
     */
    #[ApiResponse(BroadcastResource::class, collection: true, paginated: true)]
    public function index(Request $request): JsonResponse
    {
        $broadcasts = Broadcast::query()
            ->join('broadcast_recipients', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
            ->where('broadcast_recipients.driver_id', $this->driver($request)->getKey())
            ->select('broadcasts.*', 'broadcast_recipients.read_at')
            ->orderByDesc('broadcasts.sent_at')
            // `sent_at` n'est pas unique : le curseur a besoin d'une clé de
            // départage stable, sinon des lignes peuvent être sautées.
            ->orderByDesc('broadcasts.id')
            ->cursorPaginate(max(1, min($request->integer('per_page', 20), 50)));

        return $this->okApiResponse(BroadcastResource::collection($broadcasts));
    }

    /**
     * Marquer une diffusion comme lue
     *
     * Répond 404 pour une diffusion qui ne lui a pas été adressée : rien ne
     * doit fuir d'un compte à l'autre, pas même son existence.
     */
    #[ApiResponse(BroadcastResource::class)]
    public function markRead(Request $request, string $broadcast): JsonResponse
    {
        $recipient = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast)
            ->where('driver_id', $this->driver($request)->getKey())
            ->first();

        abort_if($recipient === null, 404);

        // Idempotent : une seconde lecture ne recompte pas.
        if ($recipient->read_at === null) {
            $recipient->forceFill(['read_at' => now()])->save();
            $recipient->broadcast()->increment('read_count');
        }

        return $this->okApiResponse(
            new BroadcastResource($recipient->broadcast->setAttribute('read_at', $recipient->read_at)),
        );
    }
}
