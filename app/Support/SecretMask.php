<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Aperçu masqué d'un secret enregistré.
 *
 * Un champ de clé vide ne dit pas *laquelle* est en place : on ne distingue
 * pas une clé de test d'une clé de production, ni deux comptes Wave l'un de
 * l'autre. L'aperçu répond à cette question sans publier le secret.
 *
 * Ce qui est révélé est délibérément peu : un préfixe reconnaissable quand la
 * clé en porte un (`wave_sk_live_`, `sk_test_`) et les quatre derniers
 * caractères — de quoi comparer avec la clé qu'on a sous les yeux dans la
 * console du fournisseur, pas de quoi s'en servir. Une clé courte est masquée
 * en entier : y montrer quatre caractères en dévoilerait une part réelle.
 */
class SecretMask
{
    /**
     * Nombre de caractères de fin laissés en clair.
     */
    private const VISIBLE_SUFFIX = 4;

    /**
     * En deçà, la clé est masquée sans rien laisser paraître.
     */
    private const MIN_LENGTH_FOR_SUFFIX = 12;

    /**
     * Aperçu du secret, ou `null` s'il n'y en a pas d'enregistré.
     */
    public static function preview(?string $secret): ?string
    {
        if (blank($secret)) {
            return null;
        }

        $secret = (string) $secret;
        $prefix = self::prefix($secret);
        $remainder = Str::after($secret, $prefix);

        if (mb_strlen($secret) < self::MIN_LENGTH_FOR_SUFFIX) {
            return $prefix.str_repeat('•', mb_strlen($remainder));
        }

        $suffix = mb_substr($remainder, -self::VISIBLE_SUFFIX);
        $hidden = mb_strlen($remainder) - self::VISIBLE_SUFFIX;

        return $prefix.str_repeat('•', max($hidden, 1)).$suffix;
    }

    /**
     * Préfixe conventionnel d'un fournisseur (`wave_sk_live_`, `yapi10-`,
     * `sk_test_`…), gardé en clair parce qu'il désigne l'environnement et non
     * le porteur.
     *
     * Un préfixe est fait de segments *courts et sans majuscule* (`wave`,
     * `sk`, `live`, `yapi10`) : on avance segment par segment tant qu'ils
     * ressemblent à cela, et on s'arrête au premier qui a l'allure du secret
     * lui-même. Ni le premier séparateur ni le dernier ne suffisaient — une
     * clé Wave (`yapi10-E5IuB_zhLW…`) porte des `_` dans son corps, et
     * `wave_sk_live_…` porte plusieurs segments avant le sien.
     */
    private static function prefix(string $secret): string
    {
        /** Au-delà, le segment est le secret et non plus une étiquette. */
        $maxSegment = 8;

        $prefix = '';

        foreach (self::segments($secret) as [$segment, $separator]) {
            if ($separator === '' || mb_strlen($segment) > $maxSegment || $segment !== mb_strtolower($segment)) {
                break;
            }

            $prefix .= $segment.$separator;
        }

        // Un préfixe qui mangerait presque toute la clé n'en est pas un.
        return mb_strlen($prefix) <= mb_strlen($secret) / 2 ? $prefix : '';
    }

    /**
     * Découpe la clé en paires `[segment, séparateur]`, le séparateur étant
     * vide sur le dernier segment.
     *
     * @return list<array{string, string}>
     */
    private static function segments(string $secret): array
    {
        preg_match_all('/([^_-]*)([_-]?)/u', $secret, $matches, PREG_SET_ORDER);

        return array_values(array_map(
            static fn (array $match): array => [$match[1], $match[2]],
            array_filter($matches, static fn (array $match): bool => $match[0] !== ''),
        ));
    }
}
