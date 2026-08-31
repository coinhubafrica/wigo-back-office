<?php

namespace Database\Seeders;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\DriverStatus;
use App\Enums\MessageType;
use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\BroadcastDispatcher;
use App\Services\Support\MessageService;
use App\Services\Support\SlaCalculator;
use App\Services\Support\SupportRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Jeu d'essai du support : un fil par état que la file « Requêtes » sait
 * afficher, pour que chaque branche de l'écran soit atteignable sans avoir à
 * fabriquer la donnée à la main.
 *
 * Les fils passent par les services plutôt que par les factories : les
 * compteurs, le rattachement au ticket et la numérotation sont ainsi ceux de
 * la production, pas une approximation.
 *
 * Idempotent : rejoué, il ne duplique rien (il s'arrête si des conversations
 * existent déjà). Jamais exécuté en production.
 */
class SupportSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->seedTemplates();

        if (Conversation::query()->exists()) {
            $this->command->info('SupportSeeder : conversations déjà présentes, rien à faire.');

            return;
        }

        $agent = User::query()->where('email', 'gestionnaire@atconfortplus.ci')->first()
            ?? User::query()->first();

        if ($agent === null) {
            $this->command->warn('SupportSeeder : aucun agent en base, exécuter UserSeeder avant.');

            return;
        }

        $this->broadcasts($agent);
        $this->awaitingTriage();
        $this->awaitingFirstResponse($agent);
        $this->breached($agent);
        $this->answeredAwaitingDriver($agent);
        $this->resolvedThenWroteAgain($agent);
        $this->dismissedWithoutTicket($agent);

        $this->report();
    }

    /**
     * Réponses types de l'agent.
     */
    private function seedTemplates(): void
    {
        $templates = [
            ['/remb', 'Remboursement en cours', 'Votre remboursement a été enregistré et sera crédité sous 48 heures. Merci de votre patience.', 'payment'],
            ['/doc', 'Pièce manquante', 'Pour traiter votre demande, merci de nous transmettre une photo lisible de votre pièce.', 'account'],
            ['/retrait', 'Commande à retirer', 'Votre commande est prête. Présentez-vous en agence avec votre code de retrait.', 'shop'],
            ['/merci', 'Clôture', 'Nous restons à votre disposition. Bonne route !', null],
        ];

        foreach ($templates as [$shortcut, $title, $body, $category]) {
            MessageTemplate::query()->firstOrCreate(
                ['shortcut' => $shortcut],
                ['title' => $title, 'body' => $body, 'category' => $category, 'is_active' => true],
            );
        }
    }

    /**
     * Deux diffusions : une envoyée avec ses destinataires et quelques
     * lectures, un brouillon prêt à partir.
     */
    private function broadcasts(User $agent): void
    {
        if (Broadcast::query()->exists()) {
            return;
        }

        $sent = Broadcast::query()->create([
            'title' => 'Maintenance dimanche',
            'body' => "L'application sera indisponible dimanche de 2 h à 4 h.",
            'audience' => BroadcastAudience::All,
            'status' => BroadcastStatus::Draft,
            'created_by_user_id' => $agent->getKey(),
        ]);

        app(BroadcastDispatcher::class)->dispatch($sent);

        // Deux destinataires ont ouvert : le taux de lecture a de quoi
        // s'afficher.
        $sent->recipients()->limit(2)->get()->each(
            fn (BroadcastRecipient $recipient) => $recipient->forceFill(['read_at' => now()])->save(),
        );
        $sent->forceFill(['read_count' => 2, 'sent_at' => now()->subHours(6)])->save();

        Broadcast::query()->create([
            'title' => 'Nouveaux casques en boutique',
            'body' => 'Les casques homologués sont disponibles au retrait.',
            'audience' => BroadcastAudience::Segment,
            'segment' => ['status' => [DriverStatus::Active->value]],
            'status' => BroadcastStatus::Draft,
            'created_by_user_id' => $agent->getKey(),
        ]);
    }

    /**
     * Un conducteur vient d'écrire, personne n'a encore trié : l'onglet
     * « À trier » doit le montrer en tête.
     */
    private function awaitingTriage(): void
    {
        $driver = $this->driver('+2250717738299');
        $messages = app(MessageService::class);

        $first = $messages->sendFromDriver($driver, "Bonjour, j'ai rechargé 5 000 F hier soir et mon solde n'a pas bougé.");
        $second = $messages->sendFromDriver($driver, 'Voici la référence Wave : TX-8842-091.');

        // Antidaté après coup plutôt qu'en gelant l'horloge : `setTestNow()`
        // est un outil de test, et une exception en cours de route laisserait
        // le temps figé pour tout le processus.
        $this->backdate($first, now()->subHours(2)->subMinutes(14));
        $this->backdate($second, now()->subHours(2)->subMinutes(11));
    }

    /**
     * Ticket ouvert, chronomètre de première réponse en cours.
     */
    private function awaitingFirstResponse(User $agent): void
    {
        $driver = $this->driver('+2250700000005');
        app(MessageService::class)->sendFromDriver($driver, "Je n'arrive pas à commander dans la boutique.");

        app(SupportRequestService::class)->createFromTriage(
            $this->conversationOf($driver),
            SupportRequestCategory::Shop,
            $agent,
        );
    }

    /**
     * Première réponse hors délai : la file doit le signaler « en retard ».
     */
    private function breached(User $agent): void
    {
        $driver = $this->driver('+2250700000007');

        $message = app(MessageService::class)->sendFromDriver($driver, 'Mon véhicule ne figure plus sur mon compte.');
        $request = app(SupportRequestService::class)->createFromTriage(
            $this->conversationOf($driver),
            SupportRequestCategory::Payment,
            $agent,
        );

        $twoDaysAgo = now()->subDays(2);
        $this->backdate($message, $twoDaysAgo);
        // Le message système d'ouverture suit le ticket, sinon le fil se lit
        // dans le désordre : « ouverture » daterait d'aujourd'hui sous un
        // message vieux de deux jours.
        $this->backdateSystemMessages($request, $twoDaysAgo->addMinutes(1));

        // Le ticket est réellement en dépassement : ses échéances sont
        // recalculées depuis une ouverture vieille de deux jours.
        $request->forceFill([
            'created_at' => $twoDaysAgo,
            'assigned_user_id' => $agent->getKey(),
        ])->save();
        app(SlaCalculator::class)->apply($request, $twoDaysAgo);
        $request->save();
    }

    /**
     * L'agent a répondu, la balle est chez le conducteur.
     */
    private function answeredAwaitingDriver(User $agent): void
    {
        $driver = $this->driver('+2250700000004');
        $messages = app(MessageService::class);

        $messages->sendFromDriver($driver, "Je n'ai pas reçu mon bonus du mois dernier.");
        $request = app(SupportRequestService::class)->createFromTriage(
            $this->conversationOf($driver),
            SupportRequestCategory::Account,
            $agent,
        );

        $messages->sendFromStaff($request, $agent, 'Bonjour, pouvez-vous nous confirmer votre numéro CNPS ?');
        $request->forceFill([
            'status' => SupportRequestStatus::Pending,
            'assigned_user_id' => $agent->getKey(),
        ])->save();
    }

    /**
     * Ticket résolu, puis nouveau message : il repart en tri sans rouvrir le
     * ticket clos — c'est le cœur du modèle à deux lectures.
     */
    private function resolvedThenWroteAgain(User $agent): void
    {
        $driver = $this->driver('+2250700000002');
        $messages = app(MessageService::class);
        $requests = app(SupportRequestService::class);

        $opening = $messages->sendFromDriver($driver, 'Ma commande de casque est introuvable.');
        $request = $requests->createFromTriage(
            $this->conversationOf($driver),
            SupportRequestCategory::Shop,
            $agent,
        );
        $answer = $messages->sendFromStaff($request, $agent, 'Elle vous attend au point de retrait de Cocody.');
        $requests->resolve($request->fresh());

        $nineDaysAgo = now()->subDays(9);
        $this->backdate($opening, $nineDaysAgo);
        $this->backdate($answer, $nineDaysAgo->addHours(2));
        // Ouverture juste après le message, résolution après la réponse : trois
        // heures d'écart, l'ordre du fil est celui des faits.
        $this->backdateSystemMessages($request, $nineDaysAgo->addMinutes(1), spacingMinutes: 179);

        // Les échéances suivent l'antidatage, sinon le ticket afficherait un
        // délai à venir alors qu'il est résolu depuis une semaine.
        $request->forceFill([
            'created_at' => $nineDaysAgo,
            'first_response_at' => $nineDaysAgo->addHours(2),
            'resolved_at' => $nineDaysAgo->addHours(3),
        ])->save();
        app(SlaCalculator::class)->apply($request, $nineDaysAgo);
        $request->save();

        // Suspendu, il écrit pour contester : nouveau sujet, donc nouveau tri.
        $messages->sendFromDriver($driver->fresh(), 'Pourquoi mon compte est-il suspendu ?');
    }

    /**
     * Un remerciement écarté sans ticket : la file reste propre, le message
     * demeure dans le fil du conducteur.
     */
    private function dismissedWithoutTicket(User $agent): void
    {
        $driver = $this->driver('+2250700000003');
        app(MessageService::class)->sendFromDriver($driver, 'Merci beaucoup pour votre aide !');

        app(SupportRequestService::class)->dismissUntriaged($this->conversationOf($driver), $agent);
    }

    /**
     * Recule l'horodatage d'une ligne déjà écrite, pour que le jeu d'essai
     * présente une file d'attente crédible.
     */
    private function backdate(Model $model, CarbonImmutable $at): void
    {
        $model->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        // `MessageService` a déjà recopié l'aperçu sur la conversation avec
        // l'heure réelle : sans cette reprise, la file afficherait « il y a
        // 2 minutes » pour un message antidaté de deux heures, et son tri du
        // plus ancien au plus récent perdrait tout sens.
        if ($model instanceof Message) {
            $this->syncLastMessageAt($model->conversation_id);
        }
    }

    /**
     * Antidate les messages système d'un ticket, pour que le fil se lise dans
     * l'ordre.
     */
    private function backdateSystemMessages(SupportRequest $request, CarbonImmutable $at, int $spacingMinutes = 1): void
    {
        // Espacés les uns des autres : « ouverture » puis « résolution » ne
        // peuvent pas porter le même horodatage, sinon le fil se lit à
        // l'envers autour de la réponse de l'agent.
        $request->messages()
            ->where('type', MessageType::System)
            ->orderBy('id')
            ->get()
            ->each(fn (Message $message, int $index) => $this->backdate(
                $message,
                $at->addMinutes($index * $spacingMinutes),
            ));
    }

    /**
     * Réaligne l'horodatage de la conversation sur son message le plus récent.
     */
    private function syncLastMessageAt(string $conversationId): void
    {
        $latest = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->value('created_at');

        Conversation::query()
            ->whereKey($conversationId)
            ->update(['last_message_at' => $latest]);
    }

    private function driver(string $phone): Driver
    {
        return Driver::query()->where('phone', $phone)->sole();
    }

    private function conversationOf(Driver $driver): Conversation
    {
        return Conversation::query()->where('driver_id', $driver->getKey())->sole();
    }

    private function report(): void
    {
        $this->command->table(
            ['Conducteur', 'État', 'Ce que la file doit montrer'],
            [
                ['+2250717738299', 'à trier', "2 messages non triés, en tête d'onglet"],
                ['+2250700000005', 'ouverte', 'première réponse attendue'],
                ['+2250700000007', 'ouverte', 'SLA dépassé — marquée en retard'],
                ['+2250700000004', 'en attente', 'agent a répondu, balle au conducteur'],
                ['+2250700000002', 'résolue + à trier', 'nouveau sujet après résolution'],
                ['+2250700000003', 'écartée', 'aucun ticket, message conservé'],
            ],
        );

        $this->command->info(sprintf(
            'SupportSeeder : %d conversations, %d tickets, %d réponses types.',
            Conversation::query()->count(),
            SupportRequest::query()->count(),
            MessageTemplate::query()->count(),
        ));
    }
}
