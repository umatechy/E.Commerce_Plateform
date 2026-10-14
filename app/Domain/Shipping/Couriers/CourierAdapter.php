<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;

/**
 * Phase B49 (Module 13 §42–46, §53–55, §70, §72): the contract every
 * courier integration implements. The shipping engine
 * (CourierShippingService) talks only to this interface, so a courier is
 * added as one class plus one line in CourierRegistry — never by changing
 * shipments, orders or returns (Module 13 §42).
 *
 * An adapter is stateless: one platform request in, one courier request out,
 * the answer translated into platform terms. It never writes to the
 * database and never logs credentials or addresses.
 *
 * Credentials reuse B48's write-only CredentialField and the hidden
 * ProviderCredentials value object (the same rules for every provider
 * secret on the platform).
 */
interface CourierAdapter
{
    /** Stable code stored on accounts and shipments: test_courier, … */
    public function key(): string;

    public function label(): string;

    public function capabilities(): CourierCapabilities;

    /** @return list<CredentialField> */
    public function credentialFields(): array;

    /** Book the parcel. A timeout is Unknown: the parcel may be booked, so it is never booked again blindly. */
    public function book(CourierBooking $booking, ProviderCredentials $credentials): BookingResult;

    /** Only called when capabilities()->cancel; before the courier collects the parcel. */
    public function cancel(string $trackingNumber, ProviderCredentials $credentials): CourierOutcome;

    /** Only called when capabilities()->trackingPoll: the courier's events for one parcel, oldest first. */
    public function track(string $trackingNumber, ProviderCredentials $credentials): TrackingResult;

    /**
     * The tracking number in an incoming notification, read without trusting
     * anything else in it.
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers lower-case names
     */
    public function notificationTrackingNumber(array $input, array $headers): ?string;

    /**
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers
     */
    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool;

    /**
     * When the courier says it sent the notification (Unix seconds), for the replay window.
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers
     */
    public function notificationSentAt(array $input, array $headers): ?int;
}
