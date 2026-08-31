<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FulfilmentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.product_id' => ['required', 'string', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:50'],

            /**
             * Retrait en agence ou livraison à une position.
             */
            'fulfilment_mode' => ['required', Rule::enum(FulfilmentMode::class)],

            // Agence de retrait. Facultatif : sans agence désignée, le retrait
            // s'effectue au siège ATCP (Koumassi Prodomo).
            'pickup_point_id' => [
                'nullable',
                'string',
                Rule::exists('pickup_points', 'id')->where('is_active', true),
            ],
            'latitude' => [
                Rule::requiredIf(fn (): bool => $this->input('fulfilment_mode') === FulfilmentMode::Delivery->value),
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                Rule::requiredIf(fn (): bool => $this->input('fulfilment_mode') === FulfilmentMode::Delivery->value),
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'contact_phone' => [
                Rule::requiredIf(fn (): bool => $this->input('fulfilment_mode') === FulfilmentMode::Delivery->value),
                'nullable',
                'string',
                'max:32',
            ],
            'address_hint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
