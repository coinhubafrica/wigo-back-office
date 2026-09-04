<?php

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Fleet\SaloonFleetDirectory;
use App\Settings\FleetSettings;

it('refuses to call out when the credentials are missing', function (string $method): void {
    $fleet = app(FleetSettings::class);
    $fleet->base_url = 'https://fleet-api.yango.tech';
    $fleet->park_id = 'park-123';
    $fleet->api_key = '';
    $fleet->save();

    // Sans clé, une URL vide ferait passer une configuration absente pour une
    // panne de Yango, et toute la base compterait comme « non remontée ».
    expect(fn () => iterator_to_array((new SaloonFleetDirectory)->{$method}()))
        ->toThrow(YangoFleetException::class);
})->with(['drivers', 'vehicles']);
