<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

use App\Domain\Settings\Services\ConfigService;

/**
 * The one list of online payment providers (Module 12 §85; Module 30 §27).
 *
 * - REAL_PROVIDERS move real money; production use of each waits for
 *   Umar Techy to add it to `payments.live_providers` after checking the
 *   integration in that provider's sandbox.
 * - The test wallet exists only where config('payments.simulator') is on,
 *   and never when the application runs in production.
 */
final class ProviderRegistry
{
    public const REAL_PROVIDERS = ['jazzcash', 'easypaisa'];

    public function __construct(private readonly ConfigService $config) {}

    /** @return array<string, ProviderAdapter> */
    public function all(): array
    {
        $adapters = ['jazzcash' => new JazzCashAdapter(), 'easypaisa' => new EasypaisaAdapter()];
        if (self::simulatorEnabled()) {
            $adapters['simulator'] = new SimulatorAdapter();
        }

        return $adapters;
    }

    public function find(string $key): ?ProviderAdapter
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): ProviderAdapter
    {
        return $this->find($key) ?? throw new \InvalidArgumentException("Unknown payment provider: {$key}");
    }

    public static function simulatorEnabled(): bool
    {
        return (bool) config('payments.simulator') && ! app()->isProduction();
    }

    /** Whether accounts with this provider may use the production environment. */
    public function liveAllowed(string $provider): bool
    {
        if ($provider === 'simulator') {
            return false; // the test wallet has no production
        }

        return in_array($provider, (array) $this->config->get('payments.live_providers'), true);
    }
}
