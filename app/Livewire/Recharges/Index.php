<?php

namespace App\Livewire\Recharges;

use App\Contracts\WaveClient;
use App\Enums\BackOfficeModule;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recharge\RechargeService;
use App\Settings\WaveAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal des transactions Wave : réconciliation, pas initiation.
 *
 * Le back-office ne lance jamais de recharge — c'est le conducteur qui paie
 * depuis son téléphone. L'écran sert à repérer ce qui a été encaissé sans être
 * porté au solde Yango, et à rattraper : rejouer le crédit, ou constater qu'un
 * agent l'a fait à la main.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Recharges])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $status = null;

    /** Transaction dont le rejeu attend confirmation. */
    public ?string $confirmingReplayId = null;

    /** Transaction dont le crédit manuel attend confirmation. */
    public ?string $confirmingCreditId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filterByStatus(?string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = null;
        $this->resetPage();
    }

    public function canReconcile(): bool
    {
        return Gate::allows('reconcileRecharges');
    }

    public function confirmReplay(string $id): void
    {
        $this->confirmingReplayId = $id;
    }

    public function confirmMarkCredited(string $id): void
    {
        $this->confirmingCreditId = $id;
    }

    public function cancelConfirmation(): void
    {
        $this->confirmingReplayId = null;
        $this->confirmingCreditId = null;
    }

    /**
     * Relance le crédit Yango d'une transaction encaissée mais non portée.
     */
    public function replay(RechargeService $recharges): void
    {
        Gate::authorize('reconcileRecharges');

        if ($this->confirmingReplayId === null) {
            return;
        }

        $transaction = Transaction::query()->findOrFail($this->confirmingReplayId);
        $this->confirmingReplayId = null;

        $recharges->replay($transaction, $this->agent());

        $this->dispatch('toast', message: __('backoffice.recharges.replayed'));
    }

    /**
     * Constate un crédit fait à la main sur Yango. N'appelle pas Fleet.
     */
    public function markCredited(RechargeService $recharges): void
    {
        Gate::authorize('reconcileRecharges');

        if ($this->confirmingCreditId === null) {
            return;
        }

        $transaction = Transaction::query()->findOrFail($this->confirmingCreditId);
        $this->confirmingCreditId = null;

        $recharges->markCreditedManually($transaction, $this->agent());

        $this->dispatch('toast', message: __('backoffice.recharges.marked_credited'));
    }

    public function render(WaveClient $wave): View
    {
        /** @var view-string $view */
        $view = 'livewire.recharges.index';

        return view($view, [
            'rows' => $this->rows(),
            'kpis' => $this->kpis($wave),
            'canReconcile' => $this->canReconcile(),
            'pendingConfirmation' => $this->pendingConfirmation(),
        ]);
    }

    /**
     * Transaction visée par une confirmation en cours, pour que la modale
     * puisse en afficher la référence et le montant.
     */
    private function pendingConfirmation(): ?Transaction
    {
        $id = $this->confirmingReplayId ?? $this->confirmingCreditId;

        return $id === null ? null : Transaction::query()->find($id);
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    private function rows(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Transaction> $rows */
        $rows = Transaction::query()
            ->recharges()
            ->with('driver')
            ->when($this->status !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('reference', 'like', $term)
                        ->orWhere('external_reference', 'like', $term)
                        ->orWhereHas('driver', function (Builder $driver) use ($term): void {
                            $driver->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('yango_id', 'like', $term);
                        });
                });
            })
            ->orderByDesc('initiated_at')
            ->orderBy('id')
            ->paginate(20);

        return $rows;
    }

    /**
     * Cartes de tête : ce que la journée a rapporté, et ce qui reste bloqué.
     *
     * @return array{collected_today: int, pending: int, to_replay: int, wave_balance: int|null}
     */
    private function kpis(WaveClient $wave): array
    {
        $today = Carbon::now()->toImmutable();

        return [
            'collected_today' => (int) Transaction::query()
                ->recharges()
                ->settledOn($today)
                ->sum('amount'),
            'pending' => Transaction::query()
                ->recharges()
                ->whereIn('status', [TransactionStatus::Initiated, TransactionStatus::Paid])
                ->count(),
            'to_replay' => Transaction::query()
                ->recharges()
                ->whereIn('status', [TransactionStatus::Failed, TransactionStatus::ToReview])
                ->count(),
            'wave_balance' => $wave->businessBalance(WaveAccount::Topup),
        ];
    }

    private function agent(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
