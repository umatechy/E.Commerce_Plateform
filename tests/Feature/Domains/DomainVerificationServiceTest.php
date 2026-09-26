<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Exceptions\DomainVerificationFailedException;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Services\DnsResolverContract;
use App\Domain\Domains\Services\DomainVerificationService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — DNS TXT verification: secure tokens, structural replay/
 * cross-tenant protection (verification NEVER accepts a client-
 * submitted token), correct DNS answer required (Module 19 §12-16,
 * Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * The DNS lookup itself is a fake test double (FakeDnsResolver) — this
 * sandbox's network access is disabled, so no live DNS query is ever
 * actually made; see docs/development/b14-inspection-findings.md.
 */
final class DomainVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDnsResolver(array $answers = []): DnsResolverContract
    {
        return new class($answers) implements DnsResolverContract {
            public function __construct(private array $answers) {}

            public function lookupTxtRecords(string $hostname): array
            {
                return $this->answers[$hostname] ?? [];
            }
        };
    }

    public function test_initiating_verification_generates_a_token_and_sets_status(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['normalized_hostname' => 'shop.example.com']);

        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));
        $updated = $service->initiate($domain);

        $this->assertSame('verification_required', $updated->status->value);
        $this->assertNotNull($updated->verification_token);
        $this->assertGreaterThanOrEqual(32, strlen($updated->verification_token));
    }

    public function test_two_domains_never_receive_the_same_token(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domainA = Domain::factory()->for($store)->create();
        $domainB = Domain::factory()->for($store)->create();
        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        $a = $service->initiate($domainA);
        $b = $service->initiate($domainB);

        $this->assertNotSame($a->verification_token, $b->verification_token);
    }

    public function test_verification_succeeds_when_the_correct_txt_record_is_found(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['normalized_hostname' => 'shop.example.com']);
        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));
        $domain = $service->initiate($domain);

        $dns = $this->fakeDnsResolver(['_umartechy-verify.shop.example.com' => ["umartechy-verify={$domain->verification_token}"]]);
        $service = new DomainVerificationService($dns, app(\App\Domain\Events\Support\RecordsOutboxEvents::class));
        $verified = $service->attemptVerification($domain);

        $this->assertSame('verified', $verified->status->value);
        $this->assertNotNull($verified->verified_at);
    }

    public function test_verification_fails_when_txt_record_is_missing(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['normalized_hostname' => 'shop.example.com']);
        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));
        $domain = $service->initiate($domain);

        $this->expectException(DomainVerificationFailedException::class);
        $service->attemptVerification($domain);
    }

    public function test_verification_fails_when_txt_record_has_the_wrong_value(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['normalized_hostname' => 'shop.example.com']);
        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));
        $domain = $service->initiate($domain);

        $dns = $this->fakeDnsResolver(['_umartechy-verify.shop.example.com' => ['umartechy-verify=wrong-token']]);
        $service = new DomainVerificationService($dns, app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        $this->expectException(DomainVerificationFailedException::class);
        $service->attemptVerification($domain);
    }

    public function test_expired_token_cannot_be_used_to_verify(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create([
            'status' => DomainStatus::VerificationRequired, 'verification_token' => 'sometoken',
            'verification_token_expires_at' => now()->subDay(),
        ]);
        $service = new DomainVerificationService($this->fakeDnsResolver(['_umartechy-verify.'.$domain->normalized_hostname => ['umartechy-verify=sometoken']]), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        $this->expectException(DomainVerificationFailedException::class);
        $service->attemptVerification($domain);
    }

    public function test_verification_never_reads_a_client_submitted_token_it_only_ever_reads_its_own_stored_value(): void
    {
        // Structural regression test: attemptVerification()'s signature
        // takes ONLY a Domain model, never a token parameter — proving
        // "domain_id=B, token=A" is not merely rejected but impossible
        // to even express as an API call.
        $reflection = new \ReflectionMethod(DomainVerificationService::class, 'attemptVerification');

        $this->assertCount(1, $reflection->getParameters());
        $this->assertSame('domain', $reflection->getParameters()[0]->getName());
    }

    public function test_verifying_a_domain_not_awaiting_verification_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Pending]);
        $service = new DomainVerificationService($this->fakeDnsResolver(), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        $this->expectException(DomainVerificationFailedException::class);
        $service->attemptVerification($domain);
    }
}
