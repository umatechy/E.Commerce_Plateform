<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 12 §68 "Store Admin Payment View" — staff-only (see
 * PaymentController::transactions()'s Policy check). Still never
 * includes `metadata` or the full raw webhook payload — only the
 * already-safe, structured fields every transaction carries.
 *
 * @mixin \App\Domain\Payments\Models\PaymentTransaction
 */
final class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'provider_transaction_reference' => $this->provider_transaction_reference,
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'actor_id' => $this->actor_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
