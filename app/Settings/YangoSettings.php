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
