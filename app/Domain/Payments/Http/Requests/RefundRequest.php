<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced via Gate::authorize('refund', $payment) in the controller
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
