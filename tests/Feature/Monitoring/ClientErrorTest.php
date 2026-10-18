<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Domain\Identity\Models\User;
use App\Domain\Monitoring\Models\ClientError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-25 — script errors from the admin's browsers: reported by signed-in
 * users, counted per distinct error and page (ids and query strings left
 * out), listed for platform staff only.
 */
final class ClientErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_error_on_the_same_page_is_counted_once_without_ids_or_query_strings(): void
    {
        $user = User::factory()->create();
        $report = fn (string $path) => $this->actingAs($user)->postJson('/api/v1/client-errors', [
            'message' => 'TypeError: x is undefined',
            'stack' => "TypeError: x is undefined\n    at Show (http://127.0.0.1:8000/build/assets/Show-abc.js:12:34)",
            'path' => $path,
        ]);

        $report('/orders/01M4Z8XEE33JP5PZ3W5PSY8X6E?tab=items&email=a@b.c')->assertNoContent();
        $report('/orders/01M4AKPJNTBAM2N8N0YB0Q4P80')->assertNoContent();

        $error = ClientError::query()->sole();
        $this->assertSame(2, $error->occurrences);
        $this->assertSame('/orders/:id', $error->path);
        $this->assertSame('/build/assets/Show-abc.js:12:34', $error->source);
        $this->assertSame($user->id, $error->user_id);
    }

    public function test_only_platform_staff_list_them_and_visitors_cannot_report(): void
    {
        ClientError::query()->create(['fingerprint' => str_repeat('a', 64), 'message' => 'Boom', 'path' => '/products', 'occurrences' => 3, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->postJson('/api/v1/client-errors', ['message' => 'x', 'path' => '/'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/super-admin/monitoring/client-errors')->assertForbidden();
        $staff = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($staff)->withSession(['auth.mfa_verified_at' => now()->timestamp])
            ->getJson('/api/v1/super-admin/monitoring/client-errors')->assertOk()
            ->assertJsonPath('data.0.message', 'Boom')->assertJsonPath('data.0.occurrences', 3);
    }

    public function test_errors_not_seen_for_30_days_are_pruned(): void
    {
        ClientError::query()->create(['fingerprint' => str_repeat('b', 64), 'message' => 'Old', 'path' => '/', 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(31)]);
        ClientError::query()->create(['fingerprint' => str_repeat('c', 64), 'message' => 'Recent', 'path' => '/', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('model:prune', ['--model' => [ClientError::class]])->assertSuccessful();
        $this->assertSame(['Recent'], ClientError::query()->pluck('message')->all());
    }
}
