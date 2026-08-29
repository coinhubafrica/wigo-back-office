<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCnpsReferenceRequest extends FormRequest
{
    /**
     * Bornes du RSTI : 12 % d'un revenu déclaré de 30 000 à 180 000 FCFA, soit
     * 3 600 à 21 600 FCFA par mois.
     */
    private const MIN_AMOUNT = 3600;

    private const MAX_AMOUNT = 21600;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT],
            /**
             * Mois à partir duquel le montant s'applique. Par défaut, le
             * premier jour du mois en cours.
             */
            'effective_from' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.min' => __('api.cnps.reference_bounds'),
            'amount.max' => __('api.cnps.reference_bounds'),
        ];
    }
}
