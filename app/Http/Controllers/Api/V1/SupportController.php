<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendSupportMessageRequest;
use App\Http\Requests\Api\V1\StoreSupportAttachmentRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageAttachmentResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\MessageAttachment;
use App\Services\Support\ConversationResolver;
use App\Services\Support\MessageService;
use App\Settings\SupportSettings;
use App\Support\Scramble\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le fil du conducteur avec le support.
 *
 * Un conducteur n'a qu'une conversation, permanente : il n'y a donc rien à
 * lister, et les tickets du back-office n'apparaissent nulle part dans ce
 * contrat.
 */
class SupportController extends Controller
{
    use ResolvesDriver;

    public function __construct(
        private ConversationResolver $conversations,
        private MessageService $messages,
        private SupportSettings $settings,
    ) {}

    /**
     * Fil du conducteur
     *
     * L'état de la conversation : aperçu du dernier message et nombre de
     * messages non lus, de quoi alimenter la pastille de l'écran d'accueil.
     *
     * La conversation est créée à la volée si le conducteur n'a jamais écrit,
     * pour que l'application n'ait pas de cas particulier à traiter.
     */
    #[ApiResponse(ConversationResource::class)]
    public function conversation(Request $request): JsonResponse
    {
        $conversation = $this->conversations->for($this->driver($request));

        return $this->okApiResponse(new ConversationResource($conversation));
    }

    /**
     * Messages du fil
     *
     * Tout l'historique du conducteur, du plus récent au plus ancien.
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page`
     * est plafonné à 50.
     */
    #[ApiResponse(MessageResource::class, collection: true, paginated: true)]
    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->conversations->for($this->driver($request));

        $messages = $conversation->messages()
            ->with('attachments')
            // Les ULID sont ordonnés dans le temps : la clé primaire suffit au
            // curseur, sans clé de départage supplémentaire.
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return $this->okApiResponse(MessageResource::collection($messages));
    }

    /**
     * Marquer le fil comme lu
     *
     * Remet à zéro le compteur de non-lus et horodate les messages reçus.
     * Sans effet si tout était déjà lu.
     */
    #[ApiResponse(ConversationResource::class)]
    public function markRead(Request $request): JsonResponse
    {
        $conversation = $this->conversations->for($this->driver($request));

        $this->messages->markReadForDriver($conversation);

        return $this->okApiResponse(new ConversationResource($conversation->refresh()));
    }

    /**
     * Nombre de messages non lus
     *
     * Réponse minimale, pour la pastille : l'application n'a pas à charger le
     * fil entier pour savoir s'il y a du nouveau.
     *
     * @response array{message: string, data: array{unread_count: int}}
     */
    public function unread(Request $request): JsonResponse
    {
        $conversation = Conversation::query()
            ->where('driver_id', $this->driver($request)->getKey())
            ->first();

        return $this->okApiResponse([
            // Aucune conversation : le conducteur n'a jamais écrit, et cette
            // lecture ne doit pas en créer une au passage.
            'unread_count' => $conversation === null ? 0 : $conversation->driver_unread_count,
        ]);
    }

    /**
     * Écrire au support
     *
     * Le message rejoint le fil du conducteur. S'il existe un ticket en cours,
     * il s'y rattache ; sinon il attend d'être trié par un agent — le
     * conducteur, lui, ne voit qu'une conversation continue.
     *
     * `Idempotency-Key` (UUID) est obligatoire : un renvoi après coupure
     * réseau ne doit pas poster deux fois.
     */
    #[ApiResponse(MessageResource::class)]
    public function sendMessage(SendSupportMessageRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        abort_unless(
            $this->settings->suspended_drivers_may_write || ! $driver->isSuspended(),
            403,
            __('api.forbidden'),
        );

        $attachments = $this->claimAttachments($request, $driver);

        $message = $this->messages->sendFromDriver(
            $driver,
            $request->string('body')->toString() ?: null,
            $attachments,
        );

        return $this->createdApiResponse(
            new MessageResource($message->load('attachments')),
            __('api.support.message_sent'),
        );
    }

    /**
     * Déposer une pièce jointe
     *
     * Renvoie l'identifiant à passer dans `attachment_ids` à l'envoi du
     * message. En deux temps délibérément : le dépôt est lent et se réessaie
     * seul, et l'envoi du message reste en JSON — donc compatible avec
     * l'empreinte de corps de l'idempotence.
     *
     * Une pièce jointe jamais rattachée est purgée au bout de 24 heures.
     */
    #[ApiResponse(MessageAttachmentResource::class)]
    public function uploadAttachment(StoreSupportAttachmentRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        abort_unless(
            $this->settings->suspended_drivers_may_write || ! $driver->isSuspended(),
            403,
            __('api.forbidden'),
        );

        $conversation = $this->conversations->for($driver);
        $file = $request->file('file');

        // Disque privé, comme la photo de profil : une pièce jointe est une
        // donnée personnelle et n'a pas d'URL publique devinable.
        $path = $file->store("support-attachments/{$conversation->id}", 'local');

        $attachment = MessageAttachment::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'uploaded_by_driver_id' => $driver->getKey(),
        ]);

        return $this->createdApiResponse(new MessageAttachmentResource($attachment));
    }

    /**
     * Télécharger une pièce jointe
     *
     * Accessible par URL signée seulement : le fichier vit sur le disque
     * privé et n'a pas d'URL publique. Répond 403 pour la pièce jointe d'un
     * autre conducteur — l'URL signée en atteste, la vérification aussi.
     */
    public function downloadAttachment(Request $request, string $attachment): StreamedResponse
    {
        // Le modèle est résolu ici, pas par liaison de route : la liaison
        // s'exécute avant le middleware `signed`, et une pièce jointe
        // inexistante répondrait alors 404 à une requête non signée — de quoi
        // deviner quels identifiants existent sans jamais présenter de
        // signature. Tout ce qui n'est pas légitime répond 403.
        $found = MessageAttachment::query()->with('message')->find($attachment);

        $conversation = $this->conversations->for($this->driver($request));

        abort_if(
            $found?->message?->conversation_id !== $conversation->getKey(),
            403,
            __('api.forbidden'),
        );

        $disk = Storage::disk($found->disk);

        abort_unless($disk->exists($found->path), 404);

        return $disk->response($found->path);
    }

    /**
     * Rattache les pièces jointes déposées par ce conducteur.
     *
     * Seul le déposant peut rattacher, et seulement une pièce encore libre :
     * sinon une pièce jointe déjà publiée dans un fil pourrait être recollée
     * ailleurs. Une référence inconnue est refusée plutôt qu'ignorée — mieux
     * vaut un envoi en échec qu'un message amputé sans le dire.
     *
     * @return list<MessageAttachment>
     */
    private function claimAttachments(SendSupportMessageRequest $request, Driver $driver): array
    {
        /** @var list<string> $ids */
        $ids = $request->input('attachment_ids', []);

        if ($ids === []) {
            return [];
        }

        $attachments = MessageAttachment::query()
            ->whereIn('id', $ids)
            ->whereNull('message_id')
            ->where('uploaded_by_driver_id', $driver->getKey())
            ->get();

        abort_if($attachments->count() !== count($ids), 422, __('api.support.attachment_unavailable'));

        return array_values($attachments->all());
    }

    /**
     * Taille de page demandée, bornée à 50 comme annoncé au contrat.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 50));
    }
}
