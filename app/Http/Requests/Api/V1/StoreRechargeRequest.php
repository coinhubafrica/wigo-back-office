<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreRechargeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Montant de la recharge, en francs CFA entiers.
             *
             * Le plafond JOURNALIER, lui, ne peut pas s'exprimer ici : il
             * dépend de ce que le conducteur a déjà engagé aujourd'hui, et
             * c'est `RechargeService` qui le fait respecter.
             *
             * @example 10000
             */
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('wigo.recharge.min_amount'),
                'max:'.(int) config('wigo.recharge.max_amount'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.min' => __('api.recharge.amount_below_min', [
                'min' => number_format((int) config('wigo.recharge.min_amount'), 0, ',', ' '),
            ]),
            'amount.max' => __('api.recharge.amount_above_max', [
                'max' => number_format((int) config('wigo.recharge.max_amount'), 0, ',', ' '),
            ]),
        ];
    }
}
