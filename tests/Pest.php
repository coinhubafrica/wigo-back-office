<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Cas de test
|--------------------------------------------------------------------------
|
| `TestCase` est appliqué partout, et `LazilyRefreshDatabase` à tout ce qui
| touche la base — c'est-à-dire l'ensemble des tests de fonctionnalité. Les
| tests unitaires (`tests/Unit`) n'ouvrent pas de connexion : ils ne prennent
| que le cas de base.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
