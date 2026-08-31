<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OtpChannel;
use App\Settings\OtpSettings;
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
            ?? OtpChannel::from(app(OtpSettings::class)->default_channel);
    }
}
