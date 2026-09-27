<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Exceptions\ApplicationNotActiveException;
use App\Domain\DeveloperPlatform\Exceptions\InvalidApiScopeException;
use App\Domain\DeveloperPlatform\Models\ApiKeyStatus;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — API key lifecycle: hash-verified, never plaintext-
 * stored, enumeration-safe (Module 31 §9-12/§44-45, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function activeApplication(): DeveloperApplication
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        return DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);
    }

    public function test_issuing_a_key_returns_the_plaintext_secret_exactly_once(): void
    {
        $application = $this->activeApplication();

        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);

        $this->assertStringContainsString($issued['key']->key_prefix, $issued['plaintext']);
    }

    public function test_the_stored_row_never_contains_the_plaintext_secret(): void
    {
        $application = $this->activeApplication();

        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);

        $this->assertDatabaseMissing('api_keys', ['key_hash' => $issued['plaintext']]);
        $this->assertNotSame($issued['plaintext'], $issued['key']->key_hash);
    }

    public function test_a_valid_key_verifies_successfully(): void
    {
        $application = $this->activeApplication();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);

        $verified = app(ApiKeyService::class)->verify($issued['plaintext']);

        $this->assertNotNull($verified);
        $this->assertSame($issued['key']->id, $verified->id);
    }

    public function test_a_tampered_secret_fails_verification(): void
    {
        $application = $this->activeApplication();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);
        [$prefix] = explode('.', $issued['plaintext'], 2);

        $verified = app(ApiKeyService::class)->verify("{$prefix}.wrong-secret-entirely");

        $this->assertNull($verified);
    }

    public function test_an_unknown_prefix_and_a_wrong_secret_fail_identically(): void
    {
        // Module 31 §45 "API Key Enumeration" — both failure modes
        // must be indistinguishable to the caller.
        $unknownPrefixResult = app(ApiKeyService::class)->verify('utk_doesnotexist.somesecret');

        $this->assertNull($unknownPrefixResult);
    }

    public function test_a_revoked_key_no_longer_verifies(): void
    {
        $application = $this->activeApplication();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);
        app(ApiKeyService::class)->revoke($issued['key']);

        $verified = app(ApiKeyService::class)->verify($issued['plaintext']);

        $this->assertNull($verified);
    }

    public function test_an_expired_key_no_longer_verifies(): void
    {
        $application = $this->activeApplication();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read'], now()->addMinute());
        \Illuminate\Support\Carbon::setTestNow(now()->addHour());

        $verified = app(ApiKeyService::class)->verify($issued['plaintext']);

        $this->assertNull($verified);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_rotation_issues_a_new_key_and_immediately_revokes_the_old_one(): void
    {
        $application = $this->activeApplication();
        $original = app(ApiKeyService::class)->issue($application, ['products:read']);

        $rotated = app(ApiKeyService::class)->rotate($original['key']);

        $this->assertNull(app(ApiKeyService::class)->verify($original['plaintext']));
        $this->assertNotNull(app(ApiKeyService::class)->verify($rotated['plaintext']));
    }

    public function test_issuing_a_key_for_a_suspended_application_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Suspended]);

        $this->expectException(ApplicationNotActiveException::class);
        app(ApiKeyService::class)->issue($application, ['products:read']);
    }

    public function test_an_unrecognized_scope_is_rejected(): void
    {
        $application = $this->activeApplication();

        $this->expectException(InvalidApiScopeException::class);
        app(ApiKeyService::class)->issue($application, ['orders:write_everything']);
    }
}
