<?php

use App\Domain\Payments\Http\Controllers\PaymentWebhookController;
use App\Domain\Shipping\Http\Controllers\ShipmentWebhookController;
use Illuminate\Support\Facades\Route;

// Module 12 §69 "POST /payment-webhooks/{provider}" / Non-Negotiable
// Rule #13: "Webhooks must not use Sanctum as authentication." This
// route is deliberately registered WITHOUT auth:sanctum, auth:customer,
// staff.principal, or customer.principal — trust comes entirely from
// PaymentWebhookController → PaymentService's signature verification
// against the resolved payment's own store secret (see that
// controller's docblock).
Route::post('/payment-webhooks/{provider}', PaymentWebhookController::class);

// Module 13 §53/§70 "Carrier Webhooks" (Phase B8) — same rationale,
// same no-auth-middleware registration, mirroring the payment webhook
// route exactly.
Route::post('/shipment-webhooks/{provider}', ShipmentWebhookController::class);
