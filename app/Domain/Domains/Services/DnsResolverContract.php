<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

/**
 * Module 19 §14 "DNS Verification" — the ONE abstraction point.
 * DomainVerificationService depends only on this interface, never a
 * concrete DNS call directly (mirrors every other provider-abstraction
 * pattern in this codebase — Phase B7 payment gateways, B8 carriers,
 * B21 notification channels).
 */
interface DnsResolverContract
{
    /** @return list<string> every TXT record value found for $hostname, or an empty list if none/lookup failed */
    public function lookupTxtRecords(string $hostname): array;

    /** @return list<string> every IPv4 address (A record) found for $hostname, or an empty list if none/lookup failed (Phase S2) */
    public function lookupARecords(string $hostname): array;
}
