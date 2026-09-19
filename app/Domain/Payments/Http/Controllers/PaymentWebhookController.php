<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 12 §26-30/§70 "Webhooks / Webhook API Security". Deliberately
 * NOT behind Sanctum (Non-Negotiable Rule #13: "Webhooks must not use
 * Sanctum as authentication") — this route is registered with NO auth
 * middleware at all in routes/api_v1_webhooks.php. Trust comes
 * entirely from PaymentService::handleWebhook()'s signature
 * verification against the payment's OWN store's secret — this
 * controller does not decide trust itself, it only passes the raw
 * request through.
 *
 * The {payment} route parameter is a LOOKUP KEY only (Step 10: a
 * webhook's claimed identifiers must be independently verified, never
 * trusted as authorization) — PaymentService resolves the actual
 * Payment from the payload's own provider_payment_reference field
 * and verifies the signature against THAT payment's store secret; the
 * route parameter's only job is human-readable URL routing.
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('X-Mock-Gateway-Signature');
        $externalEventId = $request->header('X-Mock-Gateway-Event-Id') ?? $request->input('event_id');

        if ($externalEventId === null) {
            // Module 12 §28: an event with no identifiable ID cannot be
            // deduplicated — reject rather than silently accepting an
            // un-idempotent-able webhook.
            return response()->json(['message' => 'Missing event identifier.'], 400);
        }

        $event = app(PaymentService::class)->handleWebhook($provider, $rawPayload, $signatureHeader, $externalEventId);

        // Always 200 for a structurally-valid, deduplicated request —
        // even a signature failure or unresolvable reference returns
        // 200 (never leaking WHY via status code to a potential
        // attacker probing for oracle behavior); the real outcome is
        // recorded internally on the PaymentWebhookEvent row.
        return response()->json(['received' => true, 'status' => $event->status->value]);
    }
}
