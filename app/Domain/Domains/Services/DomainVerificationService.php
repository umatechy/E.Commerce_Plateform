<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Exceptions\DomainVerificationFailedException;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Events\Support\RecordsOutboxEvents;
use Illuminate\Support\Str;

/**
 * Module 19 §12-16 "Verification / Verification Tokens / Verification
 * Security / DNS Verification" — Non-Negotiable. Verification NEVER
 * accepts a client-submitted token to compare against — it always
 * reads the token from the Domain row's OWN stored value, which makes
 * "domain_id=B, token=A" structurally impossible to satisfy (there is
 * no code path that takes a token as input at all).
 */
final class DomainVerificationService
{
    private const TOKEN_TTL_DAYS = 7;
    private const VERIFICATION_SUBDOMAIN_PREFIX = '_umartechy-verify';

    public function __construct(private readonly DnsResolverContract $dns, private readonly RecordsOutboxEvents $outbox) {}

    /**
     * Module 19 §13: cryptographically secure, never predictable
     * (never derived from store id, domain name, timestamp, or email).
     */
    public function initiate(Domain $domain): Domain
    {
        $domain->update([
            'verification_token' => Str::random(48),
            'verification_token_expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
            'status' => DomainStatus::VerificationRequired,
        ]);

        $this->outbox->recordEvent(
            eventType: 'domain.verification_requested',
            payload: ['domain_id' => $domain->id],
            idempotencyKey: "domain:{$domain->id}:verification_requested:{$domain->verification_token}",
        );

        return $domain->fresh();
    }

    /** The exact TXT record name/value a store owner must publish — shown to staff, never guessed by them. */
    public function verificationInstructions(Domain $domain): array
    {
        return [
            'record_type' => 'TXT',
            'record_name' => self::VERIFICATION_SUBDOMAIN_PREFIX.'.'.$domain->normalized_hostname,
            'record_value' => "umartechy-verify={$domain->verification_token}",
        ];
    }

    /**
     * @throws DomainVerificationFailedException
     */
    public function attemptVerification(Domain $domain): Domain
    {
        if ($domain->status !== DomainStatus::VerificationRequired) {
            throw new DomainVerificationFailedException('This domain is not currently awaiting verification.');
        }

        if ($domain->verification_token === null || $domain->verification_token_expires_at?->isPast()) {
            throw new DomainVerificationFailedException('The verification token has expired. Restart verification.');
        }

        $expectedValue = "umartechy-verify={$domain->verification_token}";
        $records = $this->dns->lookupTxtRecords(self::VERIFICATION_SUBDOMAIN_PREFIX.'.'.$domain->normalized_hostname);

        if (! in_array($expectedValue, $records, true)) {
            throw new DomainVerificationFailedException('The required TXT record was not found. DNS changes can take time to propagate — try again shortly.');
        }

        $domain->update(['status' => DomainStatus::Verified, 'verified_at' => now()]);

        $this->outbox->recordEvent(
            eventType: 'domain.verified',
            payload: ['domain_id' => $domain->id],
            idempotencyKey: "domain:{$domain->id}:verified",
        );

        return $domain->fresh();
    }
}
