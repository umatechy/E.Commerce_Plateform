<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWebhookSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'starts_with:https://'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string'],
        ];
    }
}
