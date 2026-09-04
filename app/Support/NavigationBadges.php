<?php

namespace App\Support;

use App\Enums\BackOfficeModule;
use App\Enums\ShopOrderStatus;
use App\Models\ShopOrder;
use App\Models\SupportRequest;

/**
 * Compteurs affichés en pastille dans la barre latérale.
 *
 * Une pastille ne dit qu'une chose : « il y a ici du travail en attente ».
 * Elle ne compte donc pas les lignes d'un module mais celles qui réclament
 * une action — les tickets encore dans la file, les commandes pas encore
 * préparées. Un module sans travail en attente n'a pas de pastille : la
 * valeur `0` est rendue comme absente pour que l'œil n'ait à repérer que
 * ce qui compte.
 *
 * Les comptes sont résolus une fois par requête (`counts()` mémoïse) car la
 * barre latérale est rendue à chaque page ; les modules absents du tableau
 * n'exécutent aucune requête.
 */
class NavigationBadges
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    /**
     * Nombre d'éléments en attente pour ce module, ou `null` s'il n'a pas de
     * pastille (module non compté, ou compte à zéro).
     */
    public function for(BackOfficeModule $module): ?int
    {
        $count = $this->counts()[$module->value] ?? 0;

        return $count > 0 ? $count : null;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return $this->counts ??= [
            BackOfficeModule::SupportRequests->value => SupportRequest::query()->live()->count(),
            BackOfficeModule::ShopOrders->value => ShopOrder::query()
                ->where('status', ShopOrderStatus::Ordered)
                ->count(),
        ];
    }
}
