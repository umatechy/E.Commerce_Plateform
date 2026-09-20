<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 21 §85-86 "Link Security / Unsubscribe Security". Deliberately
 * NOT behind Sanctum — a customer must be able to unsubscribe with no
 * login (the whole point of a one-click email unsubscribe link). Trust
 * comes from an HMAC-SHA256 signature over (store_id, channel,
 * destination), keyed by the store's own `notification_signing_secret`
 * (never exposed via any API) — mirrors the exact signature-
 * verification discipline already established for Phase B7's payment
 * webhooks and B8's carrier webhooks, applied here to a customer-
 * facing link instead of a provider-facing one.
 *
 * A forged/tampered link (wrong signature) is rejected outright — it
 * can never unsubscribe an address the requester doesn't already
 * possess a valid, store-issued link for.
 */
final class UnsubscribeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'store' => ['required', 'string'],
            'channel' => ['required', 'in:email,sms,whatsapp,push'],
            'destination' => ['required', 'string'],
            'signature' => ['required', 'string'],
        ]);

        $store = Store::query()->where('slug', $request->string('store'))->first();

        if ($store === null || ! $this->verifySignature($request, $store)) {
            // Deliberately generic — never confirm/deny whether a
            // store/destination combination exists (Module 21 §86).
            return response()->json(['message' => 'This unsubscribe link is invalid or has expired.'], 422);
        }

        app(TenantContext::class)->resolveToStore($store->id);

        NotificationSuppression::query()->firstOrCreate(
            ['channel' => $request->string('channel')->toString(), 'destination' => $request->string('destination')->toString()],
            ['reason' => 'unsubscribed'],
        );

        return response()->json(['message' => 'You have been unsubscribed successfully.']);
    }

    public static function signatureFor(Store $store, NotificationChannel $channel, string $destination): string
    {
        $payload = "{$store->id}|{$channel->value}|{$destination}";

        return hash_hmac('sha256', $payload, $store->notification_signing_secret);
    }

    private function verifySignature(Request $request, Store $store): bool
    {
        $expected = self::signatureFor($store, NotificationChannel::from($request->string('channel')->toString()), $request->string('destination')->toString());

        return hash_equals($expected, $request->string('signature')->toString());
    }
}
