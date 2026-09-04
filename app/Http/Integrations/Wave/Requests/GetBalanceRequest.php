<?php

namespace App\Http\Integrations\Wave\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Solde du compte Wave Business, pour la réconciliation en back-office.
 */
class GetBalanceRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/balance';
    }
}
