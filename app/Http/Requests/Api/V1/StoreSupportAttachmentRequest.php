<?php

namespace App\Http\Requests\Api\V1;

use App\Settings\SupportSettings;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Image seule en v1 : aucun antivirus n'existe dans la chaîne, et
             * un agent ouvrant un document déposé par un tiers sur un outil
             * interne est un risque qu'on ne sait pas encore couvrir. Le type
             * est vérifié sur le contenu, pas sur l'extension.
             */
            'file' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.app(SupportSettings::class)->attachment_max_kilobytes,
            ],
        ];
    }
}
