<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartStatus;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Support\TenantContext;

/**
 * Module 15 §33-35 "Abandoned Cart Recovery / Rules" — the one
 * concrete trigger B10 implements (see inspection findings). Emits an
 * outbox event for the future Module 21 to consume; never sends a
 * real message itself.
 *
 * Deliberately separate from Cart.status/expires_at (Phase B6) — this
 * milestone's own Step 9: "do not confuse cart expiration with
 * marketing abandonment." A cart becomes "abandoned" for MARKETING
 * purposes after a period of inactivity while still Active (has not
 * expired, has not converted) — the cart itself is never deleted or
 * altered beyond the notification-idempotency marker below.
 */
final class AbandonedCartDetectionService
{
    private const ABANDONMENT_THRESHOLD_HOURS = 2; // Module 15 §34 — documented default, no specific value given by the specification

    public function __construct(private readonly TenantContext $context, private readonly RecordsOutboxEvents $outbox) {}

    /**
     * Called by the scheduled console command. Idempotent: a cart
     * already marked `abandoned_marketing_notified_at` is never
     * re-emitted, even across many scheduler runs.
     */
    public function detectAndNotify(int $limit = 200): int
    {
        $candidates = Cart::query()->withoutTenantScope()
            ->where('status', CartStatus::Active)
            ->whereNull('abandoned_marketing_notified_at')
            ->whereNotNull('customer_id') // Module 15 marketing requires a known, consentable recipient — a guest cart has no customer to notify
            ->where('updated_at', '<=', now()->subHours(self::ABANDONMENT_THRESHOLD_HOURS))
            ->whereHas('items')
            ->limit($limit)
            ->get();

        foreach ($candidates as $cart) {
            $this->context->resolveToStore($cart->store_id);

            $cart->update(['abandoned_marketing_notified_at' => now()]);

            $this->outbox->recordEvent(
                eventType: 'marketing.abandoned_cart_detected',
                payload: ['cart_id' => $cart->id, 'customer_id' => $cart->customer_id],
                idempotencyKey: "cart:{$cart->id}:abandoned_detected",
            );
        }

        return $candidates->count();
    }
}
