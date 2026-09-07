<?php

/*
 * `/` servait une redirection vers `/login` ; c'est désormais le site
 * vitrine, dont la couverture vit dans `tests/Feature/Site/`. Ce fichier ne
 * garde que le contrat le plus élémentaire : la racine répond.
 */
it('serves a page at the root url', function (): void {
    $this->get('/')->assertOk();
});
