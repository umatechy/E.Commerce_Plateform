<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Exceptions\InvalidHostnameException;

/**
 * Module 19 §6-9 "Hostname Normalization / Domain Validation / Reserved
 * Domains" — Non-Negotiable. The ONLY place a raw hostname string is
 * turned into the canonical, comparison-safe form every other B14
 * service relies on.
 */
final class HostnameNormalizer
{
    /**
     * @throws InvalidHostnameException
     */
    public function normalize(string $input): string
    {
        $host = trim($input);

        // Module 19 §7: "do not store arbitrary full URLs where a
        // hostname is expected" — reject scheme/path/query outright
        // rather than silently stripping them (fails safe: an admin who
        // pastes a full URL by mistake gets a clear error, not a
        // silently-different hostname than they thought they entered).
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $host) || str_contains($host, '/') || str_contains($host, '?')) {
            throw new InvalidHostnameException('Enter a bare hostname, not a full URL.');
        }

        $host = rtrim($host, '.'); // trailing dot
        $host = explode(':', $host)[0]; // strip a port if present
        $host = mb_strtolower($host);

        if ($host === '' || mb_strlen($host) > 253) {
            throw new InvalidHostnameException('Invalid hostname length.');
        }

        // RFC 1035-shaped label validation — letters, digits, hyphens,
        // dot-separated labels, no leading/trailing hyphen per label.
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            && ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*localhost$/', $host)) {
            throw new InvalidHostnameException('Invalid hostname format.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Module 19 §8: "do not accept arbitrary IP addresses as
            // customer domains unless explicitly supported" — not
            // supported in B14's scope.
            throw new InvalidHostnameException('IP addresses are not supported as custom domains.');
        }

        $this->assertNotReserved($host);

        return $host;
    }

    /**
     * @throws InvalidHostnameException
     */
    private function assertNotReserved(string $host): void
    {
        $firstLabel = explode('.', $host)[0];

        if (in_array($firstLabel, config('domains.reserved_labels'), true)) {
            throw new InvalidHostnameException("The label \"{$firstLabel}\" is reserved and cannot be used.");
        }
    }
}
