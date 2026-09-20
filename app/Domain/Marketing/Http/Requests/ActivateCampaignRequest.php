<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ActivateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['scheduled_at' => ['nullable', 'date']];
    }
}
