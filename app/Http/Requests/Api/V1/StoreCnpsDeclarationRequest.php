<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreCnpsDeclarationRequest extends FormRequest
{
    /**
     * Un versement ne peut couvrir un mois futur, ni remonter au-delà de deux
     * ans : une année mal saisie est une faute de frappe, pas un arriéré.
     */
    private const MAX_MONTHS_BACK = 24;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $now = Carbon::now();

        return [
            'period' => [
                'required',
                'string',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                // `lte`/`gte` compareraient des longueurs de chaîne, pas des
                // mois : la fenêtre se vérifie explicitement.
                function (string $attribute, mixed $value, Closure $fail) use ($now): void {
                    if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
                        return;
                    }

                    if ($value > $now->format('Y-m')) {
                        $fail(__('api.cnps.period_future'));

                        return;
                    }

                    if ($value < $now->copy()->subMonths(self::MAX_MONTHS_BACK)->format('Y-m')) {
                        $fail(__('api.cnps.period_too_old'));
                    }
                },
            ],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            /**
             * Capture du paiement Wave, facultative — jpg, png ou pdf, 5 Mo au
             * plus. Le type est vérifié sur le contenu, pas sur l'extension.
             */
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.regex' => __('api.cnps.period_format'),
        ];
    }
}
