<?php

use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;

it('posts the driver list to the fleet endpoint', function (): void {
    $request = new GetAllDriversRequest('park-123');

    expect($request->resolveEndpoint())->toBe('/v1/parks/driver-profiles/list')
        ->and($request->getMethod()->value)->toBe('POST');
});

it('scopes the driver list to the park and asks for the fields the sync maps', function (): void {
    $body = (new GetAllDriversRequest('park-123'))->defaultBody();

    expect($body['query']['park']['id'])->toBe('park-123')
        ->and($body['fields']['driver_profile'])->toContain('id', 'first_name', 'last_name', 'phones', 'driver_license')
        // Le véhicule affecté voyage avec le profil : une passe alimente les deux tables.
        ->and($body['fields']['car'])->toContain('id', 'brand', 'model', 'color', 'number');
});

it('paginates the driver list by offset', function (): void {
    $body = (new GetAllDriversRequest('park-123', limit: 50, offset: 100))->defaultBody();

    expect($body['limit'])->toBe(50)
        ->and($body['offset'])->toBe(100);
});

it('posts the vehicle list to the fleet endpoint', function (): void {
    $request = new GetAllVehiclesRequest('park-123');

    expect($request->resolveEndpoint())->toBe('/v1/parks/cars/list')
        ->and($request->getMethod()->value)->toBe('POST');
});

it('paginates the vehicle list by offset', function (): void {
    $body = (new GetAllVehiclesRequest('park-123', limit: 25, offset: 50))->defaultBody();

    expect($body['query']['park']['id'])->toBe('park-123')
        ->and($body['fields']['car'])->toContain('id', 'brand', 'model', 'number')
        ->and($body['limit'])->toBe(25)
        ->and($body['offset'])->toBe(50);
});
