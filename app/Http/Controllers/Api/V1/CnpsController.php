<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CnpsReferenceSetter;
use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCnpsDeclarationRequest;
use App\Http\Requests\Api\V1\UpdateCnpsReferenceRequest;
use App\Http\Resources\CnpsStatementPayload;
use App\Models\CnpsDeclaration;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CnpsController extends Controller
{
    use ResolvesDriver;

    public function __construct(private CnpsStatementService $statement) {}

    /**
     * Mon relevé de cotisations CNPS
     *
     * Le montant mensuel de référence, le mois en cours et les douze mois
     * précédents, du plus récent au plus ancien.
     *
     * Suivi purement déclaratif : les montants sont ceux que le conducteur
     * déclare avoir payés dans Wave. Seuls les états de la CNPS font foi.
     * `status` se déduit du cumul déclaré face à la référence en vigueur ce
     * mois-là — le mois en cours vaut `pending`, jamais `late`.
     *
     * `reference` est `null` tant que le conducteur n'a fixé aucun montant ;
     * `reference_amount` l'est pour les mois antérieurs au premier montant fixé.
     *
     * Reste lisible par un conducteur suspendu, comme le profil.
     *
     * @response array{
     *     message: string,
     *     data: array{
     *         reference: array{
     *             amount: int,
     *             effective_from: string,
     *             set_by: 'driver'|'agent',
     *         }|null,
     *         current: array{
     *             period: string,
     *             label: string,
     *             reference_amount: int|null,
     *             declared_amount: int,
     *             remaining: int,
     *             progress: int,
     *             status: 'paid'|'partial'|'late'|'pending',
     *             declarations: array<int, array{
     *                 id: string,
     *                 declared_amount: int,
     *                 payment_date: string,
     *                 has_proof: bool,
     *             }>,
     *         },
     *         history: array<int, array{
     *             period: string,
     *             label: string,
     *             reference_amount: int|null,
     *             declared_amount: int,
     *             remaining: int,
     *             progress: int,
     *             status: 'paid'|'partial'|'late'|'pending',
     *             declarations: array<int, array{
     *                 id: string,
     *                 declared_amount: int,
     *                 payment_date: string,
     *                 has_proof: bool,
     *             }>,
     *         }>,
     *     },
     * }
     */
    public function show(Request $request): JsonResponse
    {
        return $this->okApiResponse(
            CnpsStatementPayload::build($this->driver($request), $this->statement),
        );
    }

    /**
     * Déclarer un versement
     *
     * Enregistre un paiement RSTI effectué dans Wave. Un mois peut être réglé
     * en plusieurs versements : chaque appel ajoute une ligne, il n'y a pas de
     * conflit sur un mois déjà déclaré.
     *
     * Le justificatif est facultatif — une capture Wave que le conducteur garde
     * pour ses propres archives (jpg, png ou pdf, 5 Mo au plus).
     *
     * @response array{
     *     message: string,
     *     data: array{
     *         id: string,
     *         period: string,
     *         declared_amount: int,
     *         payment_date: string,
     *         has_proof: bool,
     *     },
     * }
     */
    public function storeDeclaration(StoreCnpsDeclarationRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        $declaration = CnpsDeclaration::query()->create([
            'driver_id' => $driver->id,
            'period' => $request->string('period')->value(),
            'declared_amount' => $request->integer('amount'),
            'payment_date' => $request->date('payment_date'),
            // Disque privé : un justificatif de paiement nommant une personne
            // n'a rien à faire derrière une URL publique devinable.
            'proof_path' => $request->file('proof')?->store("cnps-proofs/{$driver->id}", 'local'),
            'declared_at' => Carbon::now(),
        ]);

        return $this->createdApiResponse(
            $this->declarationPayload($declaration),
            __('api.cnps.declaration_recorded'),
        );
    }

    /**
     * Fixer le montant mensuel de référence
     *
     * Le montant que le conducteur vise chaque mois. L'historique est conservé :
     * une nouvelle ligne est créée, l'ancienne reste, pour que les mois passés
     * gardent le montant qui s'appliquait à l'époque.
     *
     * @response array{
     *     message: string,
     *     data: array{
     *         amount: int,
     *         effective_from: string,
     *         set_by: 'driver'|'agent',
     *     },
     * }
     */
    public function updateReference(UpdateCnpsReferenceRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        $reference = $driver->cnpsReferences()->create([
            'amount' => $request->integer('amount'),
            'effective_from' => $request->date('effective_from') ?? Carbon::now()->startOfMonth(),
            'set_by' => CnpsReferenceSetter::Driver,
        ]);

        return $this->okApiResponse([
            'amount' => $reference->amount,
            'effective_from' => $reference->effective_from->toDateString(),
            'set_by' => $reference->set_by->value,
        ], __('api.cnps.reference_updated'));
    }

    /**
     * Télécharger le justificatif d'une déclaration
     *
     * Route signée et temporaire. La signature ne vaut pas autorisation : le
     * conducteur authentifié doit être celui qui a déposé la déclaration.
     */
    public function proof(Request $request, CnpsDeclaration $declaration): StreamedResponse
    {
        $driver = $this->driver($request);

        abort_if($declaration->driver_id !== $driver->id, 403, __('api.forbidden'));
        abort_if($declaration->proof_path === null, 404, __('api.cnps.proof_missing'));

        $disk = Storage::disk('local');

        abort_unless($disk->exists($declaration->proof_path), 404, __('api.cnps.proof_missing'));

        return $disk->download($declaration->proof_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function declarationPayload(CnpsDeclaration $declaration): array
    {
        return [
            'id' => $declaration->id,
            'period' => $declaration->period,
            'declared_amount' => $declaration->declared_amount,
            'payment_date' => $declaration->payment_date->toDateString(),
            'has_proof' => $declaration->proof_path !== null,
        ];
    }
}
