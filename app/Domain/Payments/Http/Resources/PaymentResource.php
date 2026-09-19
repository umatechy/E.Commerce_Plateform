<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 12 §67 "Customer Payment View" — this exact field list is
 * what BOTH the customer-facing and staff-facing payment endpoints
 * return (staff sees additionally via PaymentTransactionResource, a
 * separate endpoint — see PaymentController::transactions()). NEVER
 * includes: merchant secrets, internal fraud signals, provider
 * credentials, sensitive gateway metadata (Module 12 §67's explicit
 * "never expose" list) — `metadata` itself is never serialized here at
 * all, only the specific safe fields below.
 */
final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
