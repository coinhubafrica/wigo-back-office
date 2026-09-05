<?php

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Yango\SaloonYangoDirectory;
use App\Services\Yango\YangoConnectionTester;
use App\Settings\YangoSettings;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Toute attente de ces chemins passe par la façade `Sleep`, jamais par
 * `usleep` : c'est ce qui rend l'espacement vérifiable et la suite instantanée.
 * Un test de rythme sans `Sleep::fake()` préalable rendrait la suite lente
 * plutôt que rouge — c'est le mode d'échec à guetter en revue.
 */
function yangoPacingSettings(int $delayMs = 250): void
{
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = 'secret-key';
    $yango->page_delay_ms = $delayMs;
    $yango->save();
}

/**
 * Page de `$count` profils, de quoi remplir (ou non) la page demandée.
 *
 * @return array<string, mixed>
 */
function yangoPacingPage(int $count): array
{
    return [
        'driver_profiles' => array_map(
            fn (int $i): array => ['driver_profile' => ['id' => "YAN-{$i}"]],
            range(1, max($count, 1)),
        ),
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('breathes between pages but never before the first', function (): void {
    yangoPacingSettings();

    // Deux pages pleines puis une courte : la boucle s'arrête sur la troisième.
    MockClient::global([
        MockResponse::make(yangoPacingPage(2), 200),
        MockResponse::make(yangoPacingPage(2), 200),
        MockResponse::make(['driver_profiles' => []], 200),
    ]);

    Sleep::fake();

    $profiles = iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    expect($profiles)->toHaveCount(4);

    // Trois pages, deux intervalles : le premier appel ne paie rien.
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 250.0, 2);
});

it('does not breathe at all when the delay is zero', function (): void {
    yangoPacingSettings(delayMs: 0);

    MockClient::global([
        MockResponse::make(yangoPacingPage(2), 200),
        MockResponse::make(['driver_profiles' => []], 200),
    ]);

    Sleep::fake();

    iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    Sleep::assertNeverSlept();
});

it('costs the connection tester nothing', function (): void {
    // Le testeur sort après une ligne : il abandonne le générateur avant que la
    // boucle n'atteigne sa pause. C'est ce qui autorise à laisser l'écran
    // « Paramètres » appeler le même code que la passe complète.
    yangoPacingSettings();

    MockClient::global([
        MockResponse::make(yangoPacingPage(1), 200),
    ]);

    Sleep::fake();

    expect(app(YangoConnectionTester::class)->test()->succeeded)->toBeTrue();

    Sleep::assertNeverSlept();
});

it('waits as long as Yango asks before retrying a 429', function (): void {
    yangoPacingSettings(delayMs: 0);

    MockClient::global([
        MockResponse::make(['message' => 'slow down'], 429, ['Retry-After' => '30']),
        MockResponse::make(['driver_profiles' => []], 200),
    ]);

    Sleep::fake();

    iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    Sleep::assertSlept(fn ($duration): bool => $duration->totalSeconds === 30.0, 1);
});

it('falls back to a default wait when Yango names none', function (): void {
    yangoPacingSettings(delayMs: 0);

    MockClient::global([
        MockResponse::make(['message' => 'slow down'], 429),
        MockResponse::make(['driver_profiles' => []], 200),
    ]);

    Sleep::fake();

    iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    Sleep::assertSlept(fn ($duration): bool => $duration->totalSeconds === 30.0, 1);
});

it('caps an absurd Retry-After rather than parking the worker', function (): void {
    yangoPacingSettings(delayMs: 0);

    MockClient::global([
        MockResponse::make(['message' => 'slow down'], 429, ['Retry-After' => '86400']),
        MockResponse::make(['driver_profiles' => []], 200),
    ]);

    Sleep::fake();

    iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    Sleep::assertSlept(fn ($duration): bool => $duration->totalSeconds === 120.0, 1);
});

it('surfaces a persistent 429 as a transient refusal, not a refused key', function (): void {
    // Le statut décide du sort du job : 429 doit se remettre en file, pas
    // échouer franchement comme le ferait un 401.
    yangoPacingSettings(delayMs: 0);

    MockClient::global([
        MockResponse::make(['message' => 'slow down'], 429),
        MockResponse::make(['message' => 'slow down'], 429),
        MockResponse::make(['message' => 'slow down'], 429),
        MockResponse::make(['message' => 'slow down'], 429),
    ]);

    Sleep::fake();

    $refusal = null;

    try {
        iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);
    } catch (YangoFleetException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->getStatusCode())->toBe(429);
});
