<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Driver;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Les routes mobiles sont protégées par `auth:sanctum` et n'authentifient que
 * des conducteurs. Ce trait fournit l'accès typé correspondant.
 */
trait ResolvesDriver
{
    protected function driver(Request $request): Driver
    {
        $driver = $request->user();

        if (! $driver instanceof Driver) {
            throw new RuntimeException('La route mobile exige un conducteur authentifié.');
        }

        return $driver;
    }
}
