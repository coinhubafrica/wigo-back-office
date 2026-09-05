<?php

namespace App\Console\Concerns;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Lecture des options `--from` / `--to` des commandes de synchronisation datée.
 *
 * Deux commandes les portent, avec la même forme et les mêmes refus : autant
 * lire la période une fois. La fenêtre par défaut est hier→aujourd'hui, parce
 * qu'une course terminée tard ou une transaction réglée après coup n'apparaît
 * qu'une fois la journée passée — ne regarder qu'aujourd'hui les perdrait.
 */
trait ResolvesSyncPeriod
{
    /**
     * Jours de la période demandée, bornes comprises.
     *
     * Rend `null` quand la période est illisible ou à l'envers — l'appelant
     * sort alors en échec, avant d'avoir mis quoi que ce soit en file.
     *
     * @return list<Carbon>|null
     */
    private function resolvePeriod(): ?array
    {
        // Une date fournie mais illisible est un échec, jamais un repli
        // silencieux sur la valeur par défaut : synchroniser une autre période
        // que celle demandée serait pire que de ne rien faire.
        $from = $this->day('from', Carbon::yesterday()->startOfDay());
        $to = $this->day('to', Carbon::today()->startOfDay());

        if ($from === null || $to === null) {
            return null;
        }

        if ($to->lessThan($from)) {
            $this->components->error('La fin de période précède son début.');

            return null;
        }

        $days = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }

    /**
     * Jour porté par une option, ou son défaut quand l'option est absente.
     * `null` signale une option présente mais illisible.
     */
    private function day(string $option, Carbon $default): ?Carbon
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (InvalidFormatException) {
            $this->components->error(sprintf('Date illisible : %s (attendu AAAA-MM-JJ).', $value));

            return null;
        }
    }
}
