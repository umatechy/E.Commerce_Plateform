<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Shipping\Models\ShipmentStatus;
use Carbon\CarbonImmutable;

/**
 * "Test courier": a courier that moves no parcel and calls nothing, so the
 * whole courier flow (booking, label, cancel, tracking, failed delivery and
 * return to sender, signed notifications, return pickup) can be used and
 * tested before a real courier is chosen and its sandbox credentials exist
 * (owner decision 2: no courier is hard-coded before that check).
 *
 * Only where config('couriers.simulator') is on — local and test systems —
 * and never in production (CourierRegistry).
 *
 * The parcel moves one step a minute after booking: picked up, in transit,
 * out for delivery, delivered. A receiver address containing:
 *   RTO     — delivery fails, the parcel comes back (returned to sender);
 *   NOBOOK  — the booking is refused;
 *   TIMEOUT — the booking gets no answer (unknown).
 * The tracking number carries the scenario and the booking time, so a read
 * needs nothing stored here.
 */
final class TestCourierAdapter implements CourierAdapter
{
    public const SIGNATURE_HEADER = 'x-test-courier-signature';

    public const TIMESTAMP_HEADER = 'x-test-courier-timestamp';

    public function key(): string
    {
        return 'test_courier';
    }

    public function label(): string
    {
        return 'Test courier';
    }

    public function capabilities(): CourierCapabilities
    {
        return new CourierCapabilities(
            booking: true, label: false, cancel: true, trackingPoll: true,
            notifications: true, signedNotifications: true, cod: true, returnPickup: true,
            verified: true, note: 'Moves no parcel. Local and test systems only. The platform prints the label.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('account_number', 'Test account number', secret: false, maxLength: 40),
            new CredentialField('api_key', 'Test API key', secret: true, maxLength: 100),
        ];
    }

    public function book(CourierBooking $booking, ProviderCredentials $credentials): BookingResult
    {
        $address = strtoupper($booking->receiver->oneLine().' '.$booking->sender->oneLine());
        if (str_contains($address, 'NOBOOK')) {
            return new BookingResult(CourierOutcome::Failed, message: 'The test courier does not serve this address.');
        }
        if (str_contains($address, 'TIMEOUT')) {
            return new BookingResult(CourierOutcome::Unknown, message: 'No answer from the test courier.');
        }
        $scenario = str_contains($address, 'RTO') ? 'R' : 'D';
        $tracking = 'TC'.$scenario.strtoupper(base_convert((string) now()->getTimestamp(), 10, 36)).strtoupper(substr(hash('sha256', $booking->reference), 0, 4));

        return new BookingResult(CourierOutcome::Succeeded, $tracking, 'TCREF-'.strtoupper(substr(hash('sha256', $booking->reference), 0, 10)), null, 'Booked with the test courier.');
    }

    public function cancel(string $trackingNumber, ProviderCredentials $credentials): CourierOutcome
    {
        $booked = $this->bookedAt($trackingNumber);

        return $booked !== null && $booked->diffInSeconds(now()) < 60 ? CourierOutcome::Succeeded : CourierOutcome::Failed;
    }

    public function track(string $trackingNumber, ProviderCredentials $credentials): TrackingResult
    {
        $booked = $this->bookedAt($trackingNumber);
        if ($booked === null) {
            return new TrackingResult(CourierOutcome::Failed, message: 'Unknown tracking number.');
        }
        $steps = str_starts_with($trackingNumber, 'TCR')
            ? [[ShipmentStatus::PickedUp, 'Picked up from the sender', 'Lahore'], [ShipmentStatus::InTransit, 'Arrived at the hub', 'Lahore hub'], [ShipmentStatus::OutForDelivery, 'Out for delivery', null], [ShipmentStatus::DeliveryFailed, 'Customer not available', null], [ShipmentStatus::Returning, 'Returning to the sender', 'Lahore hub'], [ShipmentStatus::Returned, 'Returned to the sender', 'Lahore']]
            : [[ShipmentStatus::PickedUp, 'Picked up from the sender', 'Lahore'], [ShipmentStatus::InTransit, 'Arrived at the hub', 'Lahore hub'], [ShipmentStatus::OutForDelivery, 'Out for delivery', null], [ShipmentStatus::Delivered, 'Delivered', null]];
        $minutes = (int) floor($booked->diffInSeconds(now()) / 60);
        $updates = [];
        foreach ($steps as $index => [$status, $description, $location]) {
            if ($index + 1 > $minutes) {
                break;
            }
            $updates[] = new TrackingUpdate($status, "tc-{$index}", $booked->addMinutes($index + 1), 'TC-'.strtoupper($status->value), $description, $location);
        }

        return new TrackingResult(CourierOutcome::Succeeded, $updates);
    }

    public function notificationTrackingNumber(array $input, array $headers): ?string
    {
        return isset($input['tracking_number']) && is_string($input['tracking_number']) && $input['tracking_number'] !== '' ? $input['tracking_number'] : null;
    }

    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool
    {
        $signature = $headers[self::SIGNATURE_HEADER] ?? null;
        $timestamp = $headers[self::TIMESTAMP_HEADER] ?? null;
        if (! is_string($signature) || ! is_string($timestamp) || $credentials->get('api_key') === '') {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $credentials->get('api_key')), $signature);
    }

    public function notificationSentAt(array $input, array $headers): ?int
    {
        $timestamp = $headers[self::TIMESTAMP_HEADER] ?? null;

        return is_string($timestamp) && ctype_digit($timestamp) ? (int) $timestamp : null;
    }

    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    private function bookedAt(string $trackingNumber): ?CarbonImmutable
    {
        if (preg_match('/^TC[DR]([0-9A-Z]+)[0-9A-F]{4}$/', $trackingNumber, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) base_convert(strtolower($m[1]), 36, 10));
    }
}
