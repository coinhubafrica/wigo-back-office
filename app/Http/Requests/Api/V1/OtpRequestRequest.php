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
            'channel' => ['sometimes', Rule::enum(OtpChannel::class)],
        ];
    }

    public function channel(): OtpChannel
    {
        return $this->enum('channel', OtpChannel::class)
            ?? OtpChannel::from((string) config('wigo.otp.default_channel'));
    }
}
