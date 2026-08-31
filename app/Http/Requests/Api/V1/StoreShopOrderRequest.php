<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FulfilmentMode;
use App\Models\PickupPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopOrderRequest extends FormRequest
{
    /**
     * Complète un retrait qui ne désigne aucune agence lorsqu'il n'y en a
     * qu'une d'ouverte : les versions de l'application antérieures à
     * `GET /shop/pickup-points` n'ont aucun identifiant à envoyer, et il n'y a
     * alors pas d'ambiguïté sur l'agence visée.
     *
     * Dès qu'une seconde agence est ouverte, on ne devine plus : la règle
     * `requiredIf` reprend la main et l'application doit choisir, plutôt que
     * d'aiguiller le conducteur vers la mauvaise agence sans le dire.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('fulfilment_mode') !== FulfilmentMode::Pickup->value) {
            return;
        }

        if ($this->filled('pickup_point_id')) {
            return;
        }

        // `take(2)` suffit à trancher « exactement une » sans charger tout le
        // référentiel.
        $active = PickupPoint::query()
            ->where('is_active', true)
            ->take(2)
            ->get();

        if ($active->count() !== 1) {
            return;
        }

        $this->merge(['pickup_point_id' => $active->first()?->getKey()]);
    }

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

            /**
             * Agence de retrait, parmi celles que rend `GET /shop/pickup-points`.
             *
             * Obligatoire en mode `pickup`, sauf s'il n'existe qu'une seule
             * agence ouverte : elle est alors prise par défaut. Une livraison
             * désigne à la place une position et un numéro où joindre le
             * conducteur.
             */
            'pickup_point_id' => [
                Rule::requiredIf(fn (): bool => $this->input('fulfilment_mode') === FulfilmentMode::Pickup->value),
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
