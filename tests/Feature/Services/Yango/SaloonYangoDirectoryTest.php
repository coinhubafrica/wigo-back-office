<?php

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Yango\SaloonYangoDirectory;
use App\Settings\YangoSettings;

it('refuses to call out when the credentials are missing', function (string $method): void {
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = '';
    $yango->save();

    // Sans clé, une URL vide ferait passer une configuration absente pour une
    // panne de Yango, et toute la base compterait comme « non remontée ».
    expect(fn () => iterator_to_array((new SaloonYangoDirectory)->{$method}()))
        ->toThrow(YangoFleetException::class);
})->with(['drivers', 'vehicles']);
