<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Payments\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase B48 — Module 12 §68 "Store Admin Payment View": an attempt with
 * what staff need to investigate it (failure reason, provider code and
 * message, provider reference, checks). Never credentials, never the full
 * wallet number (it is not stored).
 *
 * @mixin PaymentAttempt
 */
final class StaffPaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new PaymentAttemptResource($this->resource))->toArray($request),
            'purpose' => $this->purpose,
            'environment' => $this->environment,
            'failure_category' => $this->failure_category,
            'failure_label' => $this->failureCategory()?->staffLabel(),
            'provider_code' => $this->provider_code,
            'provider_message' => $this->provider_message,
            'provider_transaction_reference' => $this->provider_transaction_reference,
            'confirmed_amount_minor' => $this->confirmed_amount_minor,
            'confirmed_currency' => $this->confirmed_currency,
            'check_count' => $this->check_count,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
        ];
    }
}
