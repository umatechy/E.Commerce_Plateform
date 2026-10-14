<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Shipping\Couriers\BookingResult;
use App\Domain\Shipping\Couriers\CourierAdapter;
use App\Domain\Shipping\Couriers\CourierBooking;
use App\Domain\Shipping\Couriers\CourierOutcome;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Couriers\TrackingResult;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Models\CourierAccount;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Models\ShipmentWebhookEvent;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Phase B49 (gap G9 shipping side; Module 13 §42–46, §53–55, §70, §72,
 * §97–98): everything that talks to a courier. ShipmentService stays the
 * only writer of shipments; this service calls the courier — always
 * outside a database transaction — and hands it the answer.
 *
 * Rules kept:
 * - a booking is requested once at a time (atomic claim); a timeout is
 *   Unknown and is never booked again without a person deciding (a second
 *   booking could send two couriers);
 * - a booked parcel is cancelled with the courier first; if the courier
 *   says no, the shipment is not cancelled;
 * - tracking is read on a schedule with back-off, and a notification is a
 *   hint that makes the engine read it now — never trusted as the status;
 * - one store's courier account is never used for another's parcel.
 */
final class CourierShippingService
{
    public function __construct(
        private readonly CourierRegistry $couriers,
        private readonly ShipmentService $shipments,
        private readonly ShippingLabelService $labels,
        private readonly TenantContext $context,
        private readonly ConfigService $config,
    ) {}

    /** Whether shipments may be booked through this account now. */
    public function usable(CourierAccount $account): bool
    {
        $adapter = $this->couriers->find($account->provider);

        return $adapter !== null && $account->is_enabled && $adapter->capabilities()->booking
            // Real couriers book real parcels only with a production account opened for live use;
            // none is, until one has been checked in its sandbox (owner decision 2).
            && ($account->provider === 'test_courier' || ($account->environment === ProviderCredentials::PRODUCTION && in_array($account->provider, CourierRegistry::realCouriers(), true)));
    }

    /** Ask the courier to take the parcel. Safe to call twice: only one request runs. */
    public function book(Shipment $shipment): Shipment
    {
        $claimed = Shipment::query()->whereKey($shipment->id)->whereIn('booking_status', ['booking', 'failed', 'unknown'])
            ->update(['booking_status' => 'requesting', 'booking_error' => null]);
        if ($claimed !== 1) {
            return $shipment->fresh() ?? $shipment;
        }
        $shipment = $shipment->fresh() ?? $shipment;
        [$adapter, $account] = $this->connection($shipment);

        $result = $this->bookingProblem($shipment, $adapter);
        if ($result === null) {
            [$sender, $receiver] = $this->labels->parties($shipment);
            $booking = new CourierBooking(
                reference: $shipment->public_id, direction: $shipment->direction, sender: $sender, receiver: $receiver,
                pieces: $shipment->pieces, weightGrams: $shipment->weight_grams ?? (int) $this->config->get('shipping.default_weight_grams'),
                codAmountMinor: $shipment->cod_amount_minor, currency: $shipment->currency,
                description: $shipment->isReturn() ? 'Customer return' : 'Order '.($shipment->order->order_number ?? ''),
                orderNumber: $shipment->order?->order_number,
            );
            try {
                $result = $adapter->book($booking, $account->providerCredentials());
            } catch (\Throwable $e) {
                report($e);
                $result = new BookingResult(CourierOutcome::Unknown, message: 'The courier call did not finish.');
            }
            $this->remember($account, $result->outcome, $result->message);
        }

        return $this->shipments->applyBooking($shipment, $result);
    }

    /**
     * Cancel before pickup: with the courier first when it was booked
     * there, then here (units back in stock, held for the order).
     *
     * @throws FulfillmentNotAllowedException
     */
    public function cancel(Shipment $shipment, ?int $actorId, ?string $note): Shipment
    {
        if ($shipment->booking_status === 'requesting') {
            throw new FulfillmentNotAllowedException('The courier booking is still being sent. Try again in a moment.');
        }
        if ($shipment->booking_status === 'booked' && $shipment->tracking_number !== null && ($adapter = $this->couriers->find($shipment->carrier)) !== null) {
            if (! $adapter->capabilities()->cancel) {
                throw new FulfillmentNotAllowedException("{$adapter->label()} bookings are cancelled in its own portal. Cancel it there, then mark the shipment cancelled.");
            }
            [, $account] = $this->connection($shipment);
            try {
                $outcome = $adapter->cancel($shipment->tracking_number, $account->providerCredentials());
            } catch (\Throwable $e) {
                report($e);
                $outcome = CourierOutcome::Unknown;
            }
            if ($outcome !== CourierOutcome::Succeeded) {
                throw new FulfillmentNotAllowedException($outcome === CourierOutcome::Failed
                    ? "{$adapter->label()} could not cancel this parcel — it may have been collected already. Check its status first."
                    : "{$adapter->label()} did not answer. Nothing was cancelled; try again.");
            }
        }

        return $this->shipments->cancel($shipment, $actorId, $note);
    }

    /** Read the courier's events for one parcel now (Module 13 §54). */
    public function refresh(Shipment $shipment): Shipment
    {
        $adapter = $this->couriers->find($shipment->carrier);
        if ($adapter === null || ! $adapter->capabilities()->trackingPoll || $shipment->tracking_number === null || $shipment->booking_status !== 'booked') {
            return $shipment;
        }
        [, $account] = $this->connection($shipment);
        try {
            $result = $adapter->track($shipment->tracking_number, $account->providerCredentials());
        } catch (\Throwable $e) {
            report($e);
            $result = new TrackingResult(CourierOutcome::Unknown, message: 'The courier call did not finish.');
        }

        if ($result->outcome === CourierOutcome::Succeeded) {
            $this->shipments->applyTracking($shipment, $result->updates, 'carrier');
            $shipment->refresh();
            $shipment->forceFill(['last_polled_at' => now(), 'poll_failures' => 0,
                'next_poll_at' => $shipment->status->isTerminal() ? null : now()->addMinutes((int) config('couriers.poll_minutes', 15))])->save();
        } else {
            // Back off: 15, 30, 60 … minutes, at most six hours (Module 13 §54).
            $failures = $shipment->poll_failures + 1;
            $wait = min((int) config('couriers.max_backoff_minutes', 360), (int) config('couriers.poll_minutes', 15) * (2 ** min($failures, 8)));
            $shipment->forceFill(['last_polled_at' => now(), 'poll_failures' => $failures, 'next_poll_at' => now()->addMinutes($wait)])->save();
        }
        $this->remember($account, $result->outcome, $result->message);

        return $shipment->fresh() ?? $shipment;
    }

    /** @return int parcels read (scheduled: shipping:track) */
    public function pollDue(int $limit = 100): int
    {
        $this->context->resolveToPlatform();
        $due = Shipment::query()->where('booking_status', 'booked')->whereNotNull('next_poll_at')->where('next_poll_at', '<=', now())
            ->orderBy('next_poll_at')->limit($limit)->get();
        foreach ($due as $shipment) {
            $this->context->resolveToStore($shipment->store_id);
            $this->refresh($shipment);
        }
        $this->context->resolveToPlatform();

        return $due->count();
    }

    /**
     * Module 13 §53: a courier notification. Exact repeats are ignored; the
     * tracking number finds the parcel; the store's own account checks the
     * signature (where the courier signs) and the time window; then the
     * courier is asked — the notification itself never sets a status.
     */
    public function handleNotification(CourierAdapter $adapter, Request $request): ShipmentWebhookEvent
    {
        $raw = $request->getContent();
        $input = $request->all();
        $headers = collect($request->headers->all())->map(fn (array $values) => $values[0] ?? null)->all();
        $eventId = 'sha256:'.hash('sha256', $adapter->key().'|'.$raw.'|'.json_encode($request->query()));

        if ($existing = ShipmentWebhookEvent::query()->where('provider', $adapter->key())->where('external_event_id', $eventId)->first()) {
            return $existing;
        }
        $tracking = $adapter->notificationTrackingNumber($input, $headers);
        $shipment = $tracking !== null ? Shipment::query()->withoutTenantScope()->where('carrier', $adapter->key())->where('tracking_number', $tracking)->first() : null;
        $event = ShipmentWebhookEvent::query()->create([
            'store_id' => $shipment?->store_id, 'shipment_id' => $shipment?->id, 'provider' => $adapter->key(),
            'external_event_id' => $eventId, 'status' => WebhookEventStatus::Received,
            'payload' => ['tracking_number' => $tracking], // only what identifies it: notices carry addresses
        ]);
        if ($shipment === null) {
            $event->update(['status' => WebhookEventStatus::Ignored, 'failure_reason' => 'No shipment with this tracking number.', 'processed_at' => now()]);

            return $event;
        }

        $this->context->resolveToStore($shipment->store_id);
        [, $account] = $this->connection($shipment);
        if ($adapter->capabilities()->signedNotifications && ! $adapter->verifyNotification($raw, $input, $headers, $account->providerCredentials())) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Signature verification failed.', 'processed_at' => now()]);

            return $event;
        }
        $sentAt = $adapter->notificationSentAt($input, $headers);
        if ($sentAt !== null && abs(now()->getTimestamp() - $sentAt) > (int) $this->config->get('payments.webhook_tolerance_seconds')) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Outside the allowed time window.', 'processed_at' => now()]);

            return $event;
        }

        $this->refresh($shipment);
        $event->update(['status' => WebhookEventStatus::Processed, 'processed_at' => now()]);

        return $event->fresh() ?? $event;
    }

    /** Why a booking cannot be asked for, or null (no courier call is made then). */
    private function bookingProblem(Shipment $shipment, CourierAdapter $adapter): ?BookingResult
    {
        [$sender, $receiver] = $this->labels->parties($shipment);
        $store = $shipment->isReturn() ? $receiver : $sender;
        $customer = $shipment->isReturn() ? $sender : $receiver;

        return match (true) {
            $shipment->isReturn() && ! $adapter->capabilities()->returnPickup => new BookingResult(CourierOutcome::Failed, message: "{$adapter->label()} does not collect returns. Use another courier for this return."),
            trim($store->address) === '' => new BookingResult(CourierOutcome::Failed, message: 'Add the address of the warehouse this ships from (Inventory → Warehouses), then book again.'),
            trim($customer->address) === '' || $customer->city === null => new BookingResult(CourierOutcome::Failed, message: 'The customer address has no street or city. Correct the order address, then book again.'),
            $customer->phone === null || trim($customer->phone) === '' => new BookingResult(CourierOutcome::Failed, message: 'Couriers need the customer’s phone number. Add it to the order, then book again.'),
            $shipment->cod_amount_minor > 0 && ! $adapter->capabilities()->cod => new BookingResult(CourierOutcome::Failed, message: "{$adapter->label()} does not collect cash on delivery."),
            default => null,
        };
    }

    /** @return array{0: CourierAdapter, 1: CourierAccount} */
    private function connection(Shipment $shipment): array
    {
        $adapter = $this->couriers->get($shipment->carrier);
        $account = CourierAccount::query()->withoutTenantScope()->where('store_id', $shipment->store_id)
            ->where('provider', $shipment->carrier)->first()
            ?? throw new FulfillmentNotAllowedException("The {$adapter->label()} account of this store is no longer connected.");

        return [$adapter, $account];
    }

    private function remember(CourierAccount $account, CourierOutcome $outcome, ?string $message): void
    {
        $account->forceFill($outcome === CourierOutcome::Succeeded
            ? ['last_success_at' => now()]
            : ['last_failure_at' => now(), 'last_failure_message' => $message !== null ? mb_substr($message, 0, 255) : null])->save();
    }
}
