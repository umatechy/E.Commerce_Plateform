<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Shipping\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 13 §53/§70 "Carrier Webhooks". Deliberately NOT behind
 * Sanctum/staff/customer middleware (mirrors Phase B7's
 * PaymentWebhookController exactly) — trust comes entirely from
 * ShipmentService::handleWebhook()'s signature verification against
 * the resolved shipment's own store secret.
 */
final class ShipmentWebhookController
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        // Phase B49: connected couriers notify the courier engine, which asks the courier itself.
        if ($adapter = app(\App\Domain\Shipping\Couriers\CourierRegistry::class)->find($provider)) {
            $event = app(\App\Domain\Shipping\Services\CourierShippingService::class)->handleNotification($adapter, $request);

            return response()->json(['received' => true, 'status' => $event->status->value]);
        }

        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('X-Mock-Courier-Signature');
        $externalEventId = $request->header('X-Mock-Courier-Event-Id') ?? $request->input('event_id');

        if ($externalEventId === null) {
            return response()->json(['message' => 'Missing event identifier.'], 400);
        }
        // Phase B49 (Module 13 §53, as Phase B48 did for payments): the sending time is signed with
        // the body and must be recent, so a captured notification cannot be replayed later.
        $timestamp = $request->header('X-Mock-Courier-Timestamp');
        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return response()->json(['message' => 'Missing timestamp.'], 400);
        }

        $event = app(ShipmentService::class)->handleWebhook($provider, $rawPayload, $signatureHeader, $externalEventId, (int) $timestamp);

        // Always 200 for a structurally-valid, deduplicated request —
        // same oracle-attack-prevention reasoning as Phase B7's
        // PaymentWebhookController.
        return response()->json(['received' => true, 'status' => $event->status->value]);
    }
}
