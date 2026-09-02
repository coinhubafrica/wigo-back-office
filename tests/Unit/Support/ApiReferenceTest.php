<?php

use App\Support\Docs\ApiReference;
use App\Support\Docs\OpenApiSpec;

/**
 * Les générateurs d'exemples affichés sur la page d'une opération : le curl
 * et l'exemple de réponse. Contre le contrat réel plutôt qu'une spec de test
 * — ce sont ses formes (idempotence, publicité, corps JSON) qui décident du
 * contenu généré.
 */
function apiReferenceUnit(): ApiReference
{
    return ApiReference::of(app(OpenApiSpec::class));
}

it('curl carries the bearer header only for non-public operations', function (): void {
    $reference = apiReferenceUnit();

    $private = $reference->operation('v1.wallet.show');
    $public = $reference->operation('v1.auth.otp.request');

    expect($reference->curlExample($private['method'], $private['path'], $private['operation']))
        ->toContain("-H 'Authorization: Bearer <jeton>'");

    expect($reference->curlExample($public['method'], $public['path'], $public['operation']))
        ->not->toContain('Authorization');
});

it('curl carries the idempotency header only when the parameter is declared', function (): void {
    $reference = apiReferenceUnit();

    $idempotent = $reference->operation('v1.shop.orders.store');
    $plain = $reference->operation('v1.wallet.show');

    expect($reference->curlExample($idempotent['method'], $idempotent['path'], $idempotent['operation']))
        ->toContain('Idempotency-Key');

    expect($reference->curlExample($plain['method'], $plain['path'], $plain['operation']))
        ->not->toContain('Idempotency-Key');
});

it('curl carries a json body only when the operation documents one', function (): void {
    $reference = apiReferenceUnit();

    $withBody = $reference->operation('v1.auth.otp.request');
    $withoutBody = $reference->operation('v1.wallet.show');

    expect($reference->curlExample($withBody['method'], $withBody['path'], $withBody['operation']))
        ->toContain('--data')
        ->toContain('Content-Type: application/json');

    expect($reference->curlExample($withoutBody['method'], $withoutBody['path'], $withoutBody['operation']))
        ->not->toContain('--data');
});

it('curl never leaves a dangling line continuation', function (): void {
    $reference = apiReferenceUnit();

    foreach ($reference->tags() as $tag) {
        foreach ($reference->operations($tag['slug']) as $entry) {
            $example = $reference->curlExample($entry['method'], $entry['path'], $entry['operation']);

            // Un `--data` peut porter un JSON multiligne : seules les lignes
            // d'options (`-H`, `-X`, l'URL) continuent, jamais celles à
            // l'intérieur du corps.
            $optionLines = array_values(array_filter(
                explode("\n", $example),
                fn (string $line): bool => str_starts_with(trim($line), '-')
                    || str_starts_with(trim($line), "'")
                    || str_starts_with(trim($line), 'curl'),
            ));

            foreach (array_slice($optionLines, 0, -1) as $line) {
                expect($line)->toEndWith('\\');
            }

            expect(array_key_last($optionLines) !== null ? $optionLines[array_key_last($optionLines)] : '')
                ->not->toEndWith('\\');
        }
    }
});

it('the response example prefers a declared example or enum over an empty value', function (): void {
    $reference = apiReferenceUnit();
    $entry = $reference->operation('v1.me');

    $schema = $reference->resolve($entry['operation']['responses']['200']['content']['application/json']['schema']);
    $example = $reference->responseExample($schema);

    $decoded = json_decode($example, true, flags: JSON_THROW_ON_ERROR);

    // `DriverResource.status` publie un enum : l'exemple doit en tenir une
    // valeur réelle, jamais une chaîne vide.
    expect($decoded['data']['status'])->toBeIn(['active', 'suspended', 'dormant']);
});

it('the response example is valid json for every documented success', function (): void {
    $reference = apiReferenceUnit();

    foreach ($reference->tags() as $tag) {
        foreach ($reference->operations($tag['slug']) as $entry) {
            foreach ($entry['operation']['responses'] ?? [] as $status => $response) {
                if ((int) $status >= 300) {
                    continue;
                }

                $response = $reference->resolve($response);
                $schema = $response['content']['application/json']['schema'] ?? null;

                if (! is_array($schema)) {
                    continue;
                }

                $example = $reference->responseExample($reference->resolve($schema));

                json_decode($example, flags: JSON_THROW_ON_ERROR);
            }
        }
    }
})->throwsNoExceptions();
