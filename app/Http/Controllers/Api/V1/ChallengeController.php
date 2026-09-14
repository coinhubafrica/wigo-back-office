<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverChallengePayload;
use App\Models\Challenge;
use App\Services\Challenges\ChallengeSyncRequester;
use App\Services\Challenges\DriverProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChallengeController extends Controller
{
    use ResolvesDriver;

    public function __construct(
        private DriverProgressService $progress,
        private ChallengeSyncRequester $sync,
    ) {}

    /**
     * Challenges en cours du conducteur
     *
     * Un élément par challenge en cours (`active` seulement), avec la
     * progression propre au conducteur : tickets détenus et courses restantes avant le prochain pour
     * une tombola, rang et prime pour un classement, gain éventuel. Les blocs
     * `ticketing`, `leaderboard` et `won` ne sont présents que lorsqu'ils
     * s'appliquent au challenge.
     *
     * `ticketing.tickets` détaille les tickets détenus, du plus ancien au plus
     * récent : leur date d'obtention et leur numéro de tirage, ce dernier
     * n'étant attribué qu'au gel du vivier.
     *
     * `rules_document` porte le règlement du challenge quand il en a un, par
     * URL signée valable une heure.
     *
     * `meta.current_week` porte les courses terminées de la semaine en cours,
     * `meta.weekly_history` les douze dernières semaines, de la plus ancienne
     * à la semaine en cours, et `meta.challenge_counts` le nombre de
     * challenges en cours par catégorie.
     *
     * `meta.prizes_won` porte les gains du conducteur tous challenges
     * confondus, du plus récent au plus ancien : un gain se constate quand la
     * période est finie, donc sur un challenge qui ne figure plus dans `data`.
     *
     * Reste accessible à un conducteur radié, comme le profil : ses
     * compteurs cessent simplement de progresser.
     *
     * @response array{
     *     message: string,
     *     data: array<int, array{
     *         id: string,
     *         reference: string,
     *         name: string,
     *         type: 'leaderboard'|'raffle'|'surprise',
     *         status: 'active',
     *         criteria_summary: string,
     *         period: array{start: string, end: string, week_iso: string|null},
     *         prize: array{name: string, photo_url: string|null}|null,
     *         rules_document: array{
     *             url: string,
     *             original_name: string,
     *             mime_type: string,
     *             size_bytes: int,
     *         }|null,
     *         ticketing?: array{
     *             trips_per_ticket: int,
     *             orders_completed: int,
     *             tickets_held: int,
     *             progress_in_block: int,
     *             orders_to_next_ticket: int,
     *             tickets: array<int, array{
     *                 id: string,
     *                 date: string,
     *                 range_number: int|null,
     *             }>,
     *         },
     *         leaderboard?: array{
     *             rank: int|null,
     *             winning_places: int,
     *             reward_amount: int|null,
     *             in_winning_range: bool,
     *         },
     *         won?: array{
     *             drawn_at: string|null,
     *             prize_name: string|null,
     *             amount: int|null,
     *             credited: bool,
     *             collection_note?: string,
     *         },
     *     }>,
     *     meta: array{
     *         current_week: array{week_iso: string, orders_completed: int},
     *         challenge_counts: array{
     *             total: int,
     *             leaderboard: int,
     *             raffle: int,
     *             surprise: int,
     *         },
     *         weekly_history: array<int, array{
     *             week_iso: string,
     *             label: string,
     *             orders_completed: int,
     *             current: bool,
     *         }>,
     *         prizes_won: array<int, array{
     *             id: string,
     *             challenge_name: string,
     *             challenge_reference: string,
     *             type: 'leaderboard'|'raffle'|'surprise',
     *             rank: int|null,
     *             amount: int|null,
     *             prize_name: string|null,
     *             prize_photo_url: string|null,
     *             drawn_at: string|null,
     *             credited: bool,
     *             credited_at: string|null,
     *             collection_note: string|null,
     *         }>,
     *     },
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        /*
        | Le planificateur ne repasse qu'à l'heure ronde : une course terminée
        | il y a dix minutes n'est pas encore en base. La lecture demande donc
        | sa propre passe, au plus une par heure et par conducteur — la
        | réponse, elle, part avec ce qui est déjà écrit : une passe Yango
        | prend trop longtemps pour qu'un écran l'attende.
        */
        $this->sync->requestFor($driver);

        $challenges = Challenge::query()
            ->with([
                'prize',
                // Seul le gain de ce conducteur nous intéresse : inutile de
                // charger tous les gagnants du challenge.
                'winners' => fn ($query) => $query->where('driver_id', $driver->id)->with('prize'),
            ])
            /*
            | Seuls les challenges en cours remontent. Un challenge passé en
            | `DrawPending` ou `PayoutPending` a fini sa période : son vivier
            | est gelé, ses compteurs ne bougent plus, et le laisser sur
            | l'écran laisserait croire qu'il reste des courses à faire.
            */
            ->where('status', ChallengeStatus::Active)
            ->orderByDesc('period_start')
            ->get();

        $data = $challenges
            ->map(fn (Challenge $challenge): array => DriverChallengePayload::build(
                $challenge,
                $driver,
                $this->progress,
            ))
            ->all();

        return $this->okApiResponse(
            $data,
            meta: [
                'current_week' => [
                    'week_iso' => Carbon::now()->format('o-\WW'),
                    'orders_completed' => $this->progress->currentWeekOrders($driver),
                ],
                /*
                | Les onglets de filtre de l'écran mobile affichent un compte
                | par catégorie, y compris zéro : le compter ici évite que
                | l'application le fasse à sa façon — un onglet manquant
                | plutôt qu'un onglet à zéro se lirait comme une panne.
                */
                'challenge_counts' => $this->countsByType($challenges),
                'weekly_history' => $this->progress->weeklyHistory($driver),
                'prizes_won' => $this->progress->prizesWon($driver),
            ],
        );
    }

    /**
     * Nombre de challenges en cours par type, plus le total.
     *
     * Toutes les clés sont présentes même à zéro : ce sont les onglets de
     * l'écran, pas une liste de ce qui existe.
     *
     * @param  Collection<int, Challenge>  $challenges
     * @return array{total: int, leaderboard: int, raffle: int, surprise: int}
     */
    private function countsByType(Collection $challenges): array
    {
        return [
            'total' => $challenges->count(),
            'leaderboard' => $challenges->where('type', ChallengeType::Leaderboard)->count(),
            'raffle' => $challenges->where('type', ChallengeType::Raffle)->count(),
            'surprise' => $challenges->where('type', ChallengeType::Surprise)->count(),
        ];
    }

    /**
     * Télécharger le règlement d'un challenge
     *
     * Accessible par URL signée seulement : le fichier vit sur le disque privé
     * et n'a pas d'URL publique. Un challenge inconnu ou sans règlement répond
     * 403, jamais 404 — l'écart entre les deux dirait quels challenges
     * existent.
     */
    public function rulesDocument(Request $request, string $challenge): StreamedResponse
    {
        // Le conducteur est résolu pour la même raison que sur les autres
        // pièces privées : la signature atteste de l'origine du lien, le jeton
        // atteste de qui le présente.
        $this->driver($request);

        /*
        | Le modèle est résolu ici, pas par liaison de route : la liaison
        | s'exécute avant le middleware `signed`, et un challenge inexistant
        | répondrait alors 404 à une requête non signée — de quoi énumérer les
        | challenges sans jamais présenter de signature.
        */
        $found = Challenge::query()->find($challenge);

        abort_if($found === null || ! $found->hasRulesDocument(), 403, __('api.forbidden'));

        $disk = Storage::disk((string) $found->rules_document_disk);

        // Après autorisation seulement : un fichier absent du disque est une
        // anomalie de stockage, la dire ne révèle rien.
        abort_unless($disk->exists((string) $found->rules_document_path), 404);

        return $disk->response(
            (string) $found->rules_document_path,
            (string) $found->rules_document_name,
        );
    }
}
