<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OtpChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtpRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            // Toujours accepté pour ne pas casser les versions de
            // l'application qui l'envoient, mais ignoré : le code part par
            // WhatsApp quel que soit le canal demandé.
            'channel' => ['sometimes', Rule::enum(OtpChannel::class)],
        ];
    }
}
