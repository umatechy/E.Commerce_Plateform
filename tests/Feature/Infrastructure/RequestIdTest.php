<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditChainVerifier;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase B29 (gap G2) — request / correlation IDs (SRS API-011;
 * Module 32 §63.3).
 */
final class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_a_request_id(): void
    {
        $id = $this->getJson('/api/v1/auth/me')->assertUnauthorized()->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($id));

        // Each request gets its own.
        $this->assertNotSame($id, $this->getJson('/api/v1/auth/me')->headers->get('X-Request-Id'));
        // Pages too.
        $this->assertTrue(Str::isUuid($this->withoutVite()->get('/login')->assertOk()->headers->get('X-Request-Id')));
    }

    public function test_a_callers_id_is_kept_only_in_a_safe_shape(): void
    {
        $this->getJson('/api/v1/auth/me', ['X-Request-Id' => 'order-sync_2026.09.30-0001'])
            ->assertHeader('X-Request-Id', 'order-sync_2026.09.30-0001');

        foreach (["short", "has space in it", "new\nline-injection", str_repeat('a', 65), '<script>alert(1)</script>'] as $unsafe) {
            $id = $this->getJson('/api/v1/auth/me', ['X-Request-Id' => $unsafe])->headers->get('X-Request-Id');
            $this->assertTrue(Str::isUuid($id), "kept an unsafe id: {$unsafe}");
        }
    }

    public function test_audit_entries_record_the_request_id_and_protect_it(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'], ['X-Request-Id' => 'trace-abc-12345'])->assertStatus(422);

        $entry = AuditLog::query()->where('action', 'auth.login.failed')->firstOrFail();
        $this->assertSame('trace-abc-12345', $entry->request_id);
        $this->assertSame('ok', app(AuditChainVerifier::class)->verify('platform')['status']);

        // The ID is part of the entry's hash: changing or removing it is detected.
        DB::table('audit_logs')->where('id', $entry->id)->update(['request_id' => 'trace-forged-0001']);
        $this->assertSame('broken', app(AuditChainVerifier::class)->verify('platform')['status']);
        DB::table('audit_logs')->where('id', $entry->id)->update(['request_id' => null]);
        $this->assertSame('broken', app(AuditChainVerifier::class)->verify('platform')['status']);
    }
}
