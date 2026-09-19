<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Module 12 §63 "Manual Payment Confirmation" — reference/notes are required for auditability. */
final class ManualConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
