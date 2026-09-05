<?php

use App\Models\Driver;
use App\Services\Yango\YangoConnectionTester;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('succeeds when Yango answers', function (): void {
    MockClient::global([yangoDriversResponse([yangoProfile()])]);

    $result = app(YangoConnectionTester::class)->test();

    expect($result->succeeded)->toBeTrue()
        ->and($result->empty)->toBeFalse();
});

it('treats an empty park as a success, not a breakdown', function (): void {
    MockClient::global([yangoDriversResponse()]);

    $result = app(YangoConnectionTester::class)->test();

    expect($result->succeeded)->toBeTrue()
        ->and($result->empty)->toBeTrue();
});

it('carries the status back when Yango refuses', function (): void {
    MockClient::global([yangoRefusal(401, 'Clé refusée')]);

    $result = app(YangoConnectionTester::class)->test();

    expect($result->succeeded)->toBeFalse()
        ->and($result->status)->toBe(401)
        ->and($result->message)->toBe('Clé refusée');
});

it('writes nothing to the park', function (): void {
    MockClient::global([yangoDriversResponse([yangoProfile()])]);

    app(YangoConnectionTester::class)->test();

    // Tester une saisie ne doit pas faire bouger le parc.
    $this->assertSame(0, Driver::query()->count());
});
