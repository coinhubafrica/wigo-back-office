<?php

namespace App\Settings;

use Spatie\LaravelSettings\Attributes\ShouldBeEncrypted;
use Spatie\LaravelSettings\Settings;

/**
 * Accès à l'API Yango Fleet, réglable depuis le back-office.
 *
 * Ces valeurs vivent en base et non dans `config/wigo.php` parce qu'elles
 * changent sans redéploiement : on doit pouvoir saisir un jeu de clés et
 * lancer `yango:sync` pour vérifier qu'il répond. Ce n'est pas un
 * interrupteur de sécurité (cf. `.ai/rules/settings.md`) — refuser ces clés à
 * l'écran n'empêcherait personne d'entrer, cela empêcherait seulement de
 * brancher le parc.
 *
 * `api_key` est chiffrée au repos par `APP_KEY` : une lecture de la table
 * `settings` ne doit pas suffire à parler au parc au nom d'At Confort Plus.
 *
 * Vide = non configuré. `SaloonYangoDirectory` refuse alors de sortir, plutôt
 * que d'appeler une URL vide et de faire passer une configuration absente pour
 * une panne de Yango.
 */
class YangoSettings extends Settings
{
    public string $base_url;

    public string $park_id;

    #[ShouldBeEncrypted]
    public string $api_key;

    /**
     * Pause entre deux pages lors d'une passe de synchronisation.
     *
     * Yango répond 429 quand la passe enchaîne les appels sans reprendre son
     * souffle. Le bon palier ne se devine pas : il s'observe contre l'API
     * vivante, d'où un réglage en base plutôt qu'une constante. Zéro désactive
     * l'espacement.
     */
    public int $page_delay_ms;

    /**
     * Point de reprise de la passe conducteurs, et son pendant véhicules.
     *
     * Yango coupe une passe avant la fin d'un grand parc : reprendre à zéro à
     * chaque tic repasserait sans fin sur les mêmes premières pages. La passe
     * note donc où elle s'est arrêtée et repart de là ; elle remet à zéro dès
     * qu'un tour complet est bouclé.
     *
     * En base plutôt qu'en cache : un cache vidé ne doit pas faire perdre la
     * progression d'une nuit de passes.
     */
    public int $drivers_offset;

    public int $vehicles_offset;

    /**
     * Début du tour en cours, au format ISO 8601, ou chaîne vide.
     *
     * Un tour de parc s'étale désormais sur plusieurs passes et plusieurs
     * heures. Le repère de fraîcheur doit donc dater du début du **tour**, pas
     * de la passe : mesuré depuis la passe, il compterait « non remontées »
     * toutes les lignes rapprochées par les passes précédentes du même tour.
     */
    public string $lap_started_at;

    public static function group(): string
    {
        return 'yango';
    }

    /**
     * Vrai quand les trois valeurs nécessaires à un appel sont présentes.
     */
    public function isConfigured(): bool
    {
        return filled($this->base_url) && filled($this->park_id) && filled($this->api_key);
    }
}
