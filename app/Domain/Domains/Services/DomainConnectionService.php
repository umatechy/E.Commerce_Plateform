<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Exceptions\DomainVerificationFailedException;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Domains\Models\SslStatus;
use Illuminate\Support\Facades\Http;

/**
 * Phase S2 (owner decision 2026-10-31): a customer's own domain on Umar
 * Techy hosting, from added to live, in order:
 *
 * 1. ownership — the TXT record (DomainVerificationService);
 * 2. pointing — the domain's A record is the shop's address
 *    (`domains.shop_ipv4`); any other address there sends visitors elsewhere;
 * 3. certificate — the shop's proxy obtains it by itself once (2) holds and
 *    the platform allows the name (TlsAllowedController); an HTTPS request
 *    that completes proves it;
 * 4. live — the domain becomes Active, and the store's primary domain if it
 *    has no live one yet.
 *
 * Every step is re-checked by `domains:check-connections` and on demand
 * ("Check now"); nothing here invents an address: without a shop IPv4
 * configured, pointing cannot be checked and the page says so.
 */
final class DomainConnectionService
{
    public function __construct(
        private readonly DnsResolverContract $dns,
        private readonly DomainVerificationService $verification,
        private readonly DomainService $domains,
    ) {}

    public static function shopAddress(): ?string
    {
        $ip = config('domains.shop_ipv4');

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }

    /**
     * The records the customer sets at their DNS provider.
     *
     * @return list<array{type: string, name: string, value: string, purpose: string}>
     */
    public function records(Domain $domain): array
    {
        $records = [];
        if ($domain->status === DomainStatus::VerificationRequired && $domain->verification_token !== null) {
            $txt = $this->verification->verificationInstructions($domain);
            $records[] = ['type' => 'TXT', 'name' => $txt['record_name'], 'value' => $txt['record_value'], 'purpose' => 'ownership'];
        }
        if (($ip = self::shopAddress()) !== null) {
            $records[] = ['type' => 'A', 'name' => $domain->normalized_hostname, 'value' => $ip, 'purpose' => 'address'];
            $records[] = ['type' => 'A', 'name' => 'www.'.$domain->normalized_hostname, 'value' => $ip, 'purpose' => 'address'];
        }

        return $records;
    }

    /**
     * Runs every step that can run now and returns the domain as it stands.
     * A missing TXT record is not an error here: the domain simply stays where it is.
     */
    public function check(Domain $domain): Domain
    {
        if ($domain->domain_type !== DomainType::CustomDomain || in_array($domain->status, [DomainStatus::Removed, DomainStatus::Disabled, DomainStatus::Suspended], true)) {
            return $domain;
        }
        if ($domain->status === DomainStatus::Pending) {
            $domain = $this->verification->initiate($domain);
        }
        if ($domain->status === DomainStatus::VerificationRequired) {
            try {
                $domain = $this->verification->attemptVerification($domain);
            } catch (DomainVerificationFailedException) {
                return $domain->fresh() ?? $domain;
            }
        }

        $domain = $this->checkPointing($domain);
        if ($domain->dns_status === 'pointing') {
            $domain = $this->checkCertificate($domain);
        }
        if ($domain->ssl_status === SslStatus::Active && $domain->status === DomainStatus::Verified) {
            $domain = $this->domains->activate($domain);
        }
        // The first live domain of a store becomes its address.
        if ($domain->status === DomainStatus::Active && ! $domain->is_primary
            && ! Domain::query()->withoutTenantScope()->where('store_id', $domain->store_id)->where('is_primary', true)
                ->where('status', DomainStatus::Active->value)->where('domain_type', DomainType::CustomDomain->value)->exists()) {
            $domain = $this->domains->setPrimary($domain);
        }

        return $domain;
    }

    private function checkPointing(Domain $domain): Domain
    {
        $ip = self::shopAddress();
        if ($ip === null) {
            return $domain;
        }
        $found = $this->dns->lookupARecords($domain->normalized_hostname);
        // Only our address: a second, foreign A record would send some visitors elsewhere.
        $status = $found === [] ? 'missing' : ($found === [$ip] ? 'pointing' : 'elsewhere');
        $domain->forceFill(['dns_status' => $status, 'dns_checked_at' => now()])->save();

        return $domain;
    }

    private function checkCertificate(Domain $domain): Domain
    {
        try {
            // TLS is verified by default: a response at all means a valid certificate for this name.
            Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->get('https://'.$domain->normalized_hostname.'/');
            $ssl = SslStatus::Active;
        } catch (\Throwable) {
            $ssl = SslStatus::Pending;
        }
        $domain->forceFill(['ssl_status' => $ssl, 'ssl_checked_at' => now()])->save();

        return $domain;
    }
}
