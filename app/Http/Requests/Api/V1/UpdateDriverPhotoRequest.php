<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverPhotoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Photo de profil : jpg, png ou webp, 5 Mo au plus. Le type est
             * vérifié sur le contenu, pas sur l'extension, et `dimensions`
             * écarte les images trop petites pour être reconnaissables.
             */
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=200,min_height=200',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.dimensions' => __('api.driver.photo_too_small'),
        ];
    }
}
