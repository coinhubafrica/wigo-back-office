<?php

namespace App\Services\Yango;

/**
 * Verdict d'un test de connexion, destiné à l'écran des réglages.
 */
class YangoConnectionResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly bool $empty = false,
        public readonly ?string $message = null,
        public readonly ?int $status = null,
    ) {}

    public static function success(bool $empty = false): self
    {
        return new self(succeeded: true, empty: $empty);
    }

    public static function failure(string $message, ?int $status = null): self
    {
        return new self(succeeded: false, message: $message, status: $status);
    }
}
