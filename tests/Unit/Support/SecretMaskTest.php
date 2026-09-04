<?php

/**
 * L'aperçu masqué d'un secret : assez pour reconnaître la clé en place,
 * jamais assez pour s'en servir.
 */

use App\Support\SecretMask;

it('has no preview without a stored secret', function (?string $secret): void {
    expect(SecretMask::preview($secret))->toBeNull();
})->with([null, '', '   ']);

it('keeps a provider prefix and the last four characters', function (): void {
    expect(SecretMask::preview('wave_sk_live_ABCDEFGHIJKL4821'))
        ->toBe('wave_sk_live_••••••••••••4821');
});

it('masks everything but the last four when there is no prefix', function (): void {
    expect(SecretMask::preview('ABCDEFGHIJKLMNOP'))->toBe('••••••••••••MNOP');
});

it('keeps a hyphen separated prefix', function (): void {
    // Une clé Wave porte son étiquette avant un `-` et des `_` dans son corps :
    // découper au dernier séparateur masquait l'étiquette utile et laissait
    // paraître un fragment du secret.
    expect(SecretMask::preview('yapi10-E5IuB_zhLWUxL1rE0p46kd45MHZATaoxyeWXD4_8c'))
        ->toStartWith('yapi10-')
        ->toEndWith('4_8c');
});

it('keeps every label segment of a multi part prefix', function (): void {
    expect(SecretMask::preview('wave_sk_live_ABCDEFGHIJKL4821'))->toStartWith('wave_sk_live_')
        ->and(SecretMask::preview('sk_test_0123456789abcdef'))->toStartWith('sk_test_');
});

it('stops the prefix at the first segment that looks like the secret', function (): void {
    // `E5IuB` a une majuscule et n'est donc pas une étiquette : le préfixe
    // s'arrête avant lui.
    expect(SecretMask::preview('yapi10-E5IuB_zhLWUxL1rE0p46kd45MHZ'))
        ->not->toContain('E5IuB');
});

it('masks a short secret entirely', function (): void {
    // Sous le seuil, quatre caractères en clair seraient une part réelle de la clé.
    expect(SecretMask::preview('short123'))->toBe('••••••••')
        ->and(SecretMask::preview('sk_abc'))->toBe('sk_•••');
});

it('never leaks more than the last four characters of the body', function (string $secret): void {
    $preview = (string) SecretMask::preview($secret);

    // Ce qui reste en clair après le dernier masque tient en quatre caractères,
    // le préfixe d'étiquette mis à part.
    $tail = str_contains($preview, '•') ? mb_substr($preview, mb_strrpos($preview, '•') + 1) : $preview;

    expect(mb_strlen($tail))->toBeLessThanOrEqual(4)
        ->and($preview)->not->toBe($secret);
})->with([
    'wave_sk_live_0123456789abcdef',
    'sk_test_0123456789abcdef',
    'no-underscore-key-0123456789',
    'AAAAAAAAAAAAAAAAAAAAAAAA',
    'yapi10-E5IuB_zhLWUxL1rE0p46kd45MHZATaoxyeWXD4_8c',
]);

it('does not treat a trailing underscore as a prefix', function (): void {
    // Un préfixe qui mange presque toute la clé n'en est pas un.
    expect(SecretMask::preview('abcdefghijklmnop_x'))->not->toStartWith('abcdefghijklmnop_');
});
