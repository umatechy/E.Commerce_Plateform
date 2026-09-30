<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Billing\Models\InvoicePayment */
final class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'reference' => $this->reference,
            'received_at' => $this->received_at->toIso8601String(),
        ];
    }
}
