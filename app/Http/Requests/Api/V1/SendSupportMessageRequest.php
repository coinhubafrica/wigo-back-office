<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SendSupportMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Texte du message. Facultatif si des pièces jointes sont
             * transmises — une photo seule est un message valable.
             */
            'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment_ids'],
            /**
             * Identifiants renvoyés par `POST /support/attachments`, à
             * rattacher à ce message. Cinq au plus.
             */
            'attachment_ids' => ['nullable', 'array', 'max:5'],
            'attachment_ids.*' => ['string', 'ulid'],
        ];
    }
}
