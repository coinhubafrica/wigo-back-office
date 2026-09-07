<?php

/**
 * L'empreinte des requêtes idempotentes.
 *
 * Le piège que ce fichier garde : un corps `multipart/form-data` embarque une
 * frontière tirée au hasard par le client, différente à chaque envoi. Empreinte
 * prise sur le corps brut, deux rejeux identiques donnaient deux empreintes et
 * le second répondait 409 — exactement ce que l'idempotence existe pour éviter.
 *
 * Ces cas construisent le corps multipart à la main, car le client de test de
 * Laravel laisse `getContent()` vide pour un envoi de fichiers : un test qui
 * passe par `->post(..., ['file' => UploadedFile::fake()])` ne reproduit pas le
 * corps réel et ne verrait jamais la régression.
 */

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Rejoue le calcul d'empreinte du middleware sur une requête donnée.
 */
function fingerprintOf(Request $request): string
{
    $middleware = new EnsureIdempotentRequest;

    $method = new ReflectionMethod($middleware, 'fingerprint');

    return (string) $method->invoke($middleware, $request);
}

/**
 * Un envoi multipart tel qu'un vrai client le pose sur `php://input` :
 * frontière aléatoire comprise.
 *
 * @param  array<string, string>  $fields
 */
function multipartRequest(array $fields, string $fileContents): Request
{
    $boundary = '----WebKitFormBoundary'.Str::random(16);
    $body = '';

    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }

    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"documents[]\"; filename=\"cg.jpg\"\r\n"
        ."Content-Type: image/jpeg\r\n\r\n{$fileContents}\r\n--{$boundary}--\r\n";

    $path = tempnam(sys_get_temp_dir(), 'cg');
    file_put_contents($path, $fileContents);

    return Request::create(
        '/api/v1/shop/orders',
        'POST',
        $fields,
        [],
        ['documents' => [new UploadedFile($path, 'cg.jpg', 'image/jpeg', test: true)]],
        ['CONTENT_TYPE' => "multipart/form-data; boundary={$boundary}"],
        $body,
    );
}

it('gives two identical multipart sends the same fingerprint', function (): void {
    $fields = ['fulfilment_mode' => 'pickup'];

    $first = multipartRequest($fields, 'photo-binaire');
    $second = multipartRequest($fields, 'photo-binaire');

    // Les frontières diffèrent — c'est bien deux envois distincts sur le fil.
    $this->assertNotSame(
        $first->headers->get('content-type'),
        $second->headers->get('content-type'),
    );

    // L'empreinte, elle, ne bouge pas : le rejeu est reconnu.
    $this->assertSame(fingerprintOf($first), fingerprintOf($second));
});

it('separates two multipart sends that differ by a field', function (): void {
    $a = multipartRequest(['fulfilment_mode' => 'pickup'], 'photo-binaire');
    $b = multipartRequest(['fulfilment_mode' => 'delivery'], 'photo-binaire');

    $this->assertNotSame(fingerprintOf($a), fingerprintOf($b));
});

it('separates two multipart sends that differ by a file', function (): void {
    $a = multipartRequest(['fulfilment_mode' => 'pickup'], 'photo-une');
    $b = multipartRequest(['fulfilment_mode' => 'pickup'], 'photo-deux');

    // Deux photos différentes ne sont pas la même commande, même à champs
    // égaux : sinon un second envoi corrigé serait pris pour un rejeu.
    $this->assertNotSame(fingerprintOf($a), fingerprintOf($b));
});

it('ignores the order in which fields were serialised', function (): void {
    $a = multipartRequest(['fulfilment_mode' => 'pickup', 'address_hint' => 'Cocody'], 'photo');
    $b = multipartRequest(['address_hint' => 'Cocody', 'fulfilment_mode' => 'pickup'], 'photo');

    $this->assertSame(fingerprintOf($a), fingerprintOf($b));
});

it('still fingerprints a json body on its raw content', function (): void {
    $body = '{"lines":[{"product_id":"x","qty":1}]}';

    $request = Request::create(
        '/api/v1/shop/orders',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $body,
    );

    // Le cas courant ne change pas de voie : corps JSON, empreinte du corps.
    $this->assertSame(hash('sha256', $body), fingerprintOf($request));
});
