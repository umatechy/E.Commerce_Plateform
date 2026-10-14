<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Providers\FailureCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase B48 — Module 12 §67, §95: an attempt as the shopper (or the store
 * owner paying an invoice) sees it. Status and a plain message only: no
 * provider codes, provider messages or internal ids.
 *
 * `state` is the one word the payment page follows:
 * waiting (ask the phone), confirming (no answer yet: do not pay again),
 * paid, failed (may try again), expired (may try again), review (a person
 * is checking it: do not pay again).
 *
 * @mixin PaymentAttempt
 */
final class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->failureCategory();
        $review = in_array($category, [FailureCategory::AmountMismatch, FailureCategory::CurrencyMismatch], true);

        return [
            'id' => $this->public_id,
            'provider' => $this->provider,
            'reference' => $this->reference,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'payer_hint' => $this->payer_hint,
            'status' => $this->status,
            'state' => match (true) {
                $review => 'review',
                $this->status === PaymentAttempt::PENDING => 'waiting',
                $this->status === PaymentAttempt::UNKNOWN => 'confirming',
                $this->status === PaymentAttempt::SUCCEEDED => 'paid',
                $this->status === PaymentAttempt::EXPIRED => 'expired',
                default => 'failed',
            },
            'message' => $category?->customerMessage(),
            // The category alone, so a storefront can say it in the shopper's language.
            'reason' => $category?->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
