<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

/**
 * The REAL production implementation — calls PHP's own
 * `dns_get_record()`. This is genuine, correct verification code, but
 * see docs/development/b14-inspection-findings.md "Inspection
 * Findings": this sandbox's network configuration disables all
 * outbound network access, so this class's actual DNS query cannot be
 * exercised here. Never claimed as executed in this environment.
 */
final class SystemDnsResolver implements DnsResolverContract
{
    public function lookupTxtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_column($records, 'txt');
    }
}
