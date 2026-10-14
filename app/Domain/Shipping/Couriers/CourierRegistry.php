<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/**
 * The one list of courier integrations (Module 13 §42; Module 30 §27).
 *
 * REAL_COURIERS is empty on purpose. Owner decision 2: the first Pakistani
 * courier is chosen from real API and sandbox availability, and none is
 * hard-coded before that check. Leopards, TCS and PostEx were looked at for
 * B49: none publishes its API guide or a sandbox openly — each gives them to
 * merchants (docs/architecture/b49-courier-shipping.md §3). Until a store's
 * courier credentials arrive, stores book with any courier outside the
 * platform and record the tracking number here ("Another courier").
 *
 * The test courier exists only where config('couriers.simulator') is on and
 * never when the application runs in production.
 */
final class CourierRegistry
{
    /** @var list<string> */
    public const REAL_COURIERS = [];

    /** Shipments booked outside the platform: the store types the courier's name and number. */
    public const MANUAL = 'manual_courier';

    /** @return list<string> couriers that may book real parcels (with a production account) */
    public static function realCouriers(): array
    {
        return self::REAL_COURIERS;
    }

    /** @return array<string, CourierAdapter> */
    public function all(): array
    {
        return self::simulatorEnabled() ? ['test_courier' => new TestCourierAdapter()] : [];
    }

    public function find(string $key): ?CourierAdapter
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): CourierAdapter
    {
        return $this->find($key) ?? throw new \InvalidArgumentException("Unknown courier: {$key}");
    }

    public static function simulatorEnabled(): bool
    {
        return (bool) config('couriers.simulator') && ! app()->isProduction();
    }
}
