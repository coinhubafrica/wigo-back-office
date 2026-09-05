<?php

namespace App\Support;

use App\Enums\BackOfficeModule;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Product;
use App\Models\SupportRequest;
use App\Models\Transaction;
use App\Models\User;

/**
 * Les alertes du tableau de bord : ce qui réclame un geste aujourd'hui.
 *
 * Une alerte n'est pas un indicateur. Les cartes du haut d'écran comptent
 * l'activité — combien de courses, combien de recharges — et restent vraies
 * même quand tout va bien. Une alerte ne s'affiche que si quelque chose est en
 * souffrance, et elle nomme le module qui répare. À zéro elle n'existe pas :
 * une liste qui dit « 0 requête hors SLA » se lit comme du bruit et fait
 * manquer la ligne qui compte.
 *
 * Le seuil de solde Yango vient du prototype (§1.3) : sous 5 000 FCFA un
 * conducteur ne peut plus prendre de course, c'est donc un manque à gagner en
 * cours, pas une statistique.
 *
 * Chaque compteur est enfermé dans la permission du module qui le porte, et
 * la requête n'est lancée qu'après : un agent qui n'a pas accès aux recharges
 * ne doit ni voir l'agrégat, ni faire tourner la requête en son nom. Même
 * discipline que `Dashboard`.
 */
class DashboardAlerts
{
    /**
     * Solde Yango sous lequel un conducteur ne peut plus travailler.
     */
    private const LOW_BALANCE_THRESHOLD = 5000;

    /**
     * @return list<array{tone: string, text: string, route: string}>
     */
    public function for(User $user): array
    {
        $alerts = [];

        if ($user->can(BackOfficeModule::SupportRequests->permission())) {
            $breached = SupportRequest::query()->live()->breached()->count();

            if ($breached > 0) {
                $alerts[] = [
                    'tone' => 'err',
                    'text' => trans_choice('backoffice.dashboard.alert_sla_breached', $breached, ['count' => $breached]),
                    'route' => BackOfficeModule::SupportRequests->route(),
                ];
            }
        }

        if ($user->can(BackOfficeModule::Recharges->permission())) {
            $failed = Transaction::query()
                ->recharges()
                ->whereIn('status', [TransactionStatus::Failed, TransactionStatus::ToReview])
                ->count();

            if ($failed > 0) {
                $alerts[] = [
                    'tone' => 'err',
                    'text' => trans_choice('backoffice.dashboard.alert_recharges_failed', $failed, ['count' => $failed]),
                    'route' => BackOfficeModule::Recharges->route(),
                ];
            }
        }

        if ($user->can(BackOfficeModule::Shop->permission())) {
            $closed = Product::query()->where('is_active', false)->count();

            if ($closed > 0) {
                $alerts[] = [
                    'tone' => 'warn',
                    'text' => trans_choice('backoffice.dashboard.alert_products_closed', $closed, ['count' => $closed]),
                    'route' => BackOfficeModule::Shop->route(),
                ];
            }
        }

        if ($user->can(BackOfficeModule::Drivers->permission())) {
            $lowBalance = Driver::query()
                ->whereNotNull('yango_balance')
                ->where('yango_balance', '<', self::LOW_BALANCE_THRESHOLD)
                ->count();

            if ($lowBalance > 0) {
                $alerts[] = [
                    'tone' => 'neutral',
                    'text' => trans_choice('backoffice.dashboard.alert_low_balance', $lowBalance, ['count' => $lowBalance]),
                    'route' => BackOfficeModule::Drivers->route(),
                ];
            }
        }

        return $alerts;
    }
}
