<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtres du journal d'audit, partagés par l'écran et par l'export.
 *
 * L'export doit rendre **exactement** ce que l'écran montrait : un journal qui
 * exporte autre chose que ce qu'il affiche ne prouve plus rien. La requête est
 * donc écrite une fois ici, et non recopiée dans le contrôleur — c'est la
 * raison d'être de cette classe.
 *
 * Le vocabulaire des périodes est **fermé** (`match`) plutôt que deux dates
 * saisies : la valeur arrive de la barre d'adresse, et une chaîne inconnue
 * retombe sur le défaut au lieu de lever. Deux champs date auraient imposé de
 * valider format et ordre à chaque requête, pour trois modes de panne.
 */
final class AuditLogFilter
{
    /** Fenêtre par défaut : la table ne fait que croître, et la vue utile est « ce qui vient de se passer ». */
    public const DEFAULT_PERIOD = '30d';

    /** Jeton d'agent désignant les écritures d'automate (`user_id` nul). */
    public const SYSTEM_AGENT = 'system';

    public function __construct(
        public readonly string $search = '',
        public readonly ?string $action = null,
        public readonly ?string $module = null,
        public readonly ?string $agent = null,
        public readonly string $period = self::DEFAULT_PERIOD,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            action: self::nullIfBlank($request->query('action')),
            module: self::nullIfBlank($request->query('module')),
            agent: self::nullIfBlank($request->query('agent')),
            period: (string) $request->query('period', self::DEFAULT_PERIOD),
        );
    }

    /**
     * Applique les filtres actifs.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->action !== null, fn (Builder $q) => $q->where('action', $this->action))
            /*
            | Une action précise l'emporte sur son module : deux filtres qui se
            | contredisent en silence sont pires qu'un seul.
            */
            ->when($this->action === null && $this->module !== null, function (Builder $q): void {
                $module = BackOfficeModule::tryFrom((string) $this->module);

                $q->whereIn('action', $module === null ? [] : array_map(
                    fn (AuditAction $action): string => $action->value,
                    AuditAction::forModule($module),
                ));
            })
            // `user_id` nul signifie « pas un agent » : webhook Wave, tâche
            // planifiée. C'est une dimension à part entière, d'où le jeton.
            ->when($this->agent === self::SYSTEM_AGENT, fn (Builder $q) => $q->whereNull('user_id'))
            ->when(
                $this->agent !== null && $this->agent !== self::SYSTEM_AGENT,
                fn (Builder $q) => $q->where('user_id', $this->agent),
            )
            ->when($this->since() !== null, fn (Builder $q) => $q->where('occurred_at', '>=', $this->since()))
            ->when($this->search !== '', function (Builder $q): void {
                $term = "%{$this->search}%";

                // Groupé : un `orWhere` à plat neutraliserait les filtres
                // au-dessus. `summary` est la phrase que l'agent lit, donc
                // celle qu'il cherche ; le nom de l'agent vit sur `users`, il
                // n'est pas dénormalisé ici.
                $q->where(function (Builder $q) use ($term): void {
                    $q->where('summary', 'like', $term)
                        ->orWhere('ip_address', 'like', $term)
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                });
            });
    }

    /**
     * Borne basse de la fenêtre, ou `null` pour tout l'historique.
     */
    public function since(): ?CarbonImmutable
    {
        return match ($this->period) {
            'today' => CarbonImmutable::now()->startOfDay(),
            '7d' => CarbonImmutable::now()->subDays(7),
            '90d' => CarbonImmutable::now()->subDays(90),
            'all' => null,
            default => CarbonImmutable::now()->subDays(30),
        };
    }

    /**
     * Les périodes proposées, du plus court au plus large.
     *
     * @return array<string, string>
     */
    public static function periods(): array
    {
        return [
            'today' => (string) __('backoffice.audit.period_today'),
            '7d' => (string) __('backoffice.audit.period_7d'),
            '30d' => (string) __('backoffice.audit.period_30d'),
            '90d' => (string) __('backoffice.audit.period_90d'),
            'all' => (string) __('backoffice.audit.period_all'),
        ];
    }

    /**
     * Y a-t-il autre chose que la fenêtre par défaut ?
     */
    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->action !== null
            || $this->module !== null
            || $this->agent !== null
            || $this->period !== self::DEFAULT_PERIOD;
    }

    /**
     * Résumé français des filtres actifs, porté par la ligne d'audit de
     * l'export : « ce qui est sorti » doit se relire sans rejouer la requête.
     */
    public function describe(): string
    {
        $parts = [self::periods()[$this->period] ?? self::periods()[self::DEFAULT_PERIOD]];

        if ($this->action !== null) {
            $parts[] = AuditAction::labelFor($this->action);
        } elseif ($this->module !== null) {
            $parts[] = BackOfficeModule::tryFrom($this->module)?->label() ?? $this->module;
        }

        if ($this->agent === self::SYSTEM_AGENT) {
            $parts[] = (string) __('backoffice.audit.system_agent');
        }

        if ($this->search !== '') {
            $parts[] = '« '.$this->search.' »';
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'search' => $this->search,
            'action' => $this->action,
            'module' => $this->module,
            'agent' => $this->agent,
            'period' => $this->period,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
