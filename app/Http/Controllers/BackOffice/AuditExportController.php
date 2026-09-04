<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV du journal d'audit, filtres de l'écran compris.
 *
 * Les filtres voyagent en paramètres d'URL : le bouton vit dans
 * `<x-slot:actions>`, rendu hors de la racine Livewire, donc il ne peut porter
 * aucun `wire:*` — et une ancre est de toute façon la bonne balise pour un
 * téléchargement. La requête elle-même n'est pas recopiée ici : elle vient de
 * `AuditLogFilter`, partagé avec l'écran, parce qu'un export qui rend autre
 * chose que ce qui était affiché ne prouve plus rien.
 */
class AuditExportController extends Controller
{
    /**
     * Au-delà, l'export est tronqué et le dit.
     *
     * Un plafond parce qu'une route GET non bornée sur une table qui ne fait
     * que croître est un déni de service auto-infligé. La mention dans le
     * fichier parce qu'un export tronqué en silence est un faux négatif : on en
     * conclurait « rien ne s'est passé après cette date ».
     */
    private const MAX_ROWS = 50000;

    /**
     * Le plafond effectif. Isolé en méthode pour qu'un test puisse éprouver la
     * branche tronquée sans écrire cinquante mille lignes.
     */
    protected function maxRows(): int
    {
        return self::MAX_ROWS;
    }

    public function __invoke(Request $request): StreamedResponse
    {
        /*
        | Première instruction, avant même de lire les filtres : la relecture à
        | l'écran laisse la trace dans l'application, l'export l'en fait sortir
        | dans un fichier qui se transmet et ne se révoque pas. Le portail est
        | ici et non en intergiciel sur la route, pour que le refus porte le
        | même corps que celui du module — sans révéler lequel des deux droits
        | manquait.
        */
        Gate::authorize('exportAuditLog');

        $filter = AuditLogFilter::fromRequest($request);
        $actor = $this->actor($request);

        $total = $filter->apply(AuditLog::query())->count();
        $truncated = $total > $this->maxRows();

        /*
        | Journalisé avant d'écrire une seule ligne : un export interrompu à
        | mi-parcours a tout de même sorti des données. Le journal auditant sa
        | propre copie est le point — c'est le geste le plus sensible du module,
        | et le seul de cet écran qui écrive.
        */
        AuditLog::record(
            action: AuditAction::AuditExported->value,
            summary: "{$actor->fullName()} a exporté le journal d'audit ({$filter->describe()}).",
            by: $actor,
            context: [
                'filters' => $filter->toQuery(),
                'rows' => min($total, $this->maxRows()),
                'truncated' => $truncated,
            ],
        );

        return response()->streamDownload(
            fn () => $this->write($filter, $truncated),
            $this->filename(),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Écrit le fichier au fil de l'eau.
     *
     * `lazyById` et non `get()` : la table est en ajout seul et ne fait que
     * croître, un chargement complet finirait par manquer de mémoire. Et non
     * `cursor()` : aucun curseur de base ne reste ouvert pendant qu'un client
     * lent consomme la réponse.
     */
    private function write(AuditLogFilter $filter, bool $truncated): void
    {
        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        /*
        | Marque d'ordre des octets, puis point-virgule comme séparateur : sans
        | les deux, Excel en français rend « Suspension d'un conducteur » en
        | caractères illisibles et met toute la ligne dans une seule colonne.
        | Chaque phrase de ce journal porte des accents.
        */
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Horodatage',
            'Action',
            "Libellé de l'action",
            'Agent',
            "Courriel de l'agent",
            'Fait',
            'Conducteur',
            'Type de cible',
            'Identifiant de la cible',
            'Adresse IP',
            'Contexte',
        ], separator: ';');

        $filter->apply(AuditLog::query())
            ->with(['user', 'driver'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($this->maxRows())
            ->lazyById(500)
            ->each(function (AuditLog $row) use ($out): void {
                // Résolu une fois : l'agent sert à deux colonnes, et son
                // absence signifie « automate » dans les deux.
                $agent = $row->user;

                fputcsv($out, [
                    // Triable dans un tableur, contrairement au format affiché.
                    $row->occurred_at->format('Y-m-d H:i:s'),
                    // Le slug brut *et* son libellé : le premier réconcilie avec
                    // la base, le second se lit.
                    $row->action,
                    AuditAction::labelFor($row->action),
                    $agent === null ? (string) __('backoffice.audit.system_agent') : $agent->fullName(),
                    $agent === null ? '' : $agent->email,
                    $row->summary,
                    $row->driver?->fullName() ?? '',
                    $row->subject_type ?? '',
                    $row->subject_id ?? '',
                    $row->ip_address ?? '',
                    // Une seule colonne, verbatim : le jeu de clés diffère d'une
                    // action à l'autre, l'éclater en colonnes est impossible.
                    $row->context === null
                        ? ''
                        : (string) json_encode($row->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], separator: ';');
            });

        if ($truncated) {
            fputcsv($out, [
                (string) __('backoffice.audit.export_truncated', ['count' => $this->maxRows()]),
            ], separator: ';');
        }

        fclose($out);
    }

    /**
     * L'agent qui exporte. La route vit derrière `auth` + `user.active`, donc
     * il est là ; le contrôle lève à la source plutôt que de laisser un `null`
     * atterrir dans la ligne d'audit, où il se lirait « automate ».
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new RuntimeException('L\'export du journal exige un agent authentifié.');
        }

        return $actor;
    }

    private function filename(): string
    {
        return (string) __('backoffice.audit.export_filename', [
            'stamp' => now()->format('Y-m-d-Hi'),
        ]);
    }
}
