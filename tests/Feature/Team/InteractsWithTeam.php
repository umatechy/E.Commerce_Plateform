<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Packages\Models\EntitlementEnforcement;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\UsagePeriod;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/** Shared set-up for the Phase G1 team tests. */
trait InteractsWithTeam
{
    protected function setUpTeam(): void
    {
        Queue::fake(); // keeps the sealed invitation text readable (delivery would clear it)
        $this->seed(PermissionSeeder::class); // system roles get their real permissions
    }

    /** @param list<string> $features */
    protected function teamStore(?int $seatLimit = null, array $features = []): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        $package = $this->entitle($store, ['orders.basic', ...$features]);
        if ($seatLimit !== null) {
            $package->entitlements()->create([
                'key' => 'max_staff_accounts', 'type' => EntitlementType::UsageLimit, 'limit_value' => $seatLimit,
                'is_unlimited' => false, 'period' => UsagePeriod::Persistent, 'enforcement' => EntitlementEnforcement::Hard,
            ]);
        }
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    protected function memberAs(Store $store, string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, $roleSlug)->id, 'status' => 'active']);

        return $user;
    }

    /** Sends an invitation as $actor and returns [public id, token from the email]. */
    protected function invite(User $actor, string $email, string $role): array
    {
        $id = $this->actingAs($actor)->postJson('/api/v1/team/invitations', ['email' => $email, 'role' => $role])
            ->assertCreated()->json('data.id');

        return [$id, $this->mailedToken($email)];
    }

    protected function mailedToken(string $email): string
    {
        $mail = NotificationMessage::query()->withoutTenantScope()->where('destination', $email)->latest('id')->firstOrFail();
        preg_match('/#token=([A-Za-z0-9]{64})/', (string) $mail->sealed_body, $m);

        return $m[1];
    }

    /**
     * Signs everyone out, as a fresh browser would be. auth:sanctum switches
     * the default guard during a staff request, so it is put back to web.
     */
    protected function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    /** Signed in with the browser (web) session, which the public invitation routes read. */
    protected function inBrowserAs(User $user): static
    {
        $this->signOut();

        return $this->actingAs($user, 'web');
    }
}
