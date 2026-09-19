<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Module 09 §43 — the module's own example reason codes, used verbatim.
            'reason' => ['required', 'in:customer_request,out_of_stock,payment_failed,address_issue,fraud_review,store_cancellation,other'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
