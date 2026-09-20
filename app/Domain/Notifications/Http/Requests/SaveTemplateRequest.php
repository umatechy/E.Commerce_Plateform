<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,sms,whatsapp,push,in_app'],
            'locale' => ['sometimes', 'string', 'max:8'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
