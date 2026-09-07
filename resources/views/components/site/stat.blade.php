@props([
    /** Valeur cible, animée depuis zéro à l'entrée dans le cadre. */
    'value',
    /** Libellé sous le chiffre. */
    'label',
])

{{--
    Une carte de chiffre clé.

    Le chiffre est rendu par le SERVEUR à sa valeur finale, mise en forme.
    Le site d'origine écrivait `0` dans le HTML et laissait le script
    remplir : sans JavaScript — et pour un robot d'indexation — la page
    annonçait « 0 chauffeurs actifs ». `siteReveal` ne fait que le ramener à
    zéro puis le recompter, il n'est jamais la source de la valeur affichée.

    Espace fine insécable (`\u{202F}`), comme les colonnes chiffrées du
    back-office.
--}}
<div data-site-reveal data-site-counter
     class="rounded-site bg-card p-[22px] text-center shadow-lift">
    <b data-site-target="{{ (int) $value }}"
       class="block text-[clamp(26px,4vw,38px)] font-bold text-primary">{{ number_format((int) $value, 0, ',', "\u{202F}") }}</b>
    <span class="text-[13.5px] font-semibold text-site-muted">{{ $label }}</span>
</div>
