<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class OtpVerifyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'code' => ['required', 'string', 'digits:'.config('wigo.otp.length')],
            'device_name' => ['required', 'string', 'max:120'],
            'terms_version' => ['sometimes', 'string', 'max:20'],
        ];
    }
}
