<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DataProtection\Jobs\RunRestoreRehearsalJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — who can reach what: the platform/tenant boundary,
 * one tenant against another, and what a response may contain
 * (Module 23 §13, §21; SRS BKP-004).
 */
final class BackupAccessBoundaryTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    public function test_platform_backup_operations_are_closed_to_store_owners_and_staff(): void
    {
        $backup = $this->platformBackup();
        $job = BackupRestoreJob::query()->create(['backup_id' => $backup->id, 'status' => 'requested']);
        $store = Store::factory()->create();
        Queue::fake();

        $attempts = [
            ['get', '/api/v1/super-admin/backups'],
            ['get', '/api/v1/super-admin/backups/summary'],
            ['post', '/api/v1/super-admin/backups'],
            ['post', "/api/v1/super-admin/backups/{$backup->public_id}/verify"],
            ['post', "/api/v1/super-admin/backups/{$backup->public_id}/rehearse"],
            ['get', '/api/v1/super-admin/restore-jobs'],
            ['post', "/api/v1/super-admin/restore-jobs/{$job->id}/authorize"],
        ];

        foreach ([$this->ownerOf($store), \App\Domain\Identity\Models\User::factory()->create()] as $user) {
            foreach ($attempts as [$method, $url]) {
                $this->actingAs($user)->json($method, $url, ['confirmation' => $backup->public_id, 'reference' => 'Incident INC-1'])->assertStatus(403);
            }
        }

        // Signed out: nothing at all.
        $this->app['auth']->forgetGuards();
        foreach ($attempts as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }

        $this->assertSame(1, Backup::query()->count());
        $this->assertSame('requested', $job->fresh()->status->value);
        Queue::assertNothingPushed();
    }

    public function test_platform_staff_can_see_the_summary_verify_and_list(): void
    {
        $backup = $this->platformBackup();
        $staff = $this->platformStaff();

        $this->actingAs($staff)->getJson('/api/v1/super-admin/backups/summary')->assertOk()
            ->assertJsonPath('data.last_verified_backup_id', $backup->public_id)
            ->assertJsonPath('data.overdue', false)
            ->assertJsonPath('data.rpo_target_hours', 24)
            ->assertJsonStructure(['data' => ['next_scheduled_backup_at', 'consecutive_scheduled_failures', 'last_rehearsal_status', 'verified_backup_count']]);

        $this->postJson("/api/v1/super-admin/backups/{$backup->public_id}/verify")->assertOk()->assertJsonPath('data.intact', true);

        $this->corrupt($backup);
        $this->postJson("/api/v1/super-admin/backups/{$backup->public_id}/verify")->assertOk()
            ->assertJsonPath('data.intact', false)->assertJsonPath('data.backup.status', 'failed');
        // A failed backup is no longer something to verify.
        $this->postJson("/api/v1/super-admin/backups/{$backup->public_id}/verify")->assertStatus(422);
    }

    public function test_a_store_sees_and_touches_only_its_own_backups(): void
    {
        [$storeA, $storeB] = [Store::factory()->create(), Store::factory()->create()];
        $ownerA = $this->ownerOf($storeA);
        $ofA = Backup::factory()->withArtifact()->create(['store_id' => $storeA->id]);
        $ofB = Backup::factory()->withArtifact()->create(['store_id' => $storeB->id]);
        $platform = $this->platformBackup();

        $list = $this->actingAs($ownerA)->getJson('/api/v1/backups')->assertOk();
        $this->assertSame([$ofA->public_id], array_column($list->json('data.data'), 'id'));
        $this->assertStringNotContainsString($ofB->public_id, $list->getContent());
        $this->assertStringNotContainsString($platform->public_id, $list->getContent());

        // Another store's backup and a platform backup, by public id and by numeric id: not found.
        foreach ([$ofB->public_id, $ofB->id, $platform->public_id, $platform->id] as $key) {
            $this->postJson("/api/v1/backups/{$key}/restore-request")->assertNotFound();
        }
        // Its own: allowed, by the id the API gave it.
        $this->postJson("/api/v1/backups/{$ofA->public_id}/restore-request")->assertCreated()->assertJsonPath('data.status', 'requested');

        $this->assertSame(1, BackupRestoreJob::query()->count());
        $this->assertSame($ofA->id, BackupRestoreJob::query()->sole()->backup_id);
    }

    public function test_guessing_ids_and_crafted_paths_find_nothing(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $other = Backup::factory()->withArtifact()->create(['store_id' => Store::factory()->create()->id]);

        // Enumeration: every id around the other store's backup answers the same.
        foreach ([$other->id - 1, $other->id, $other->id + 1, 999999] as $id) {
            $this->actingAs($owner)->postJson("/api/v1/backups/{$id}/restore-request")->assertNotFound();
        }

        // Path traversal and storage paths in the id are just ids that do not exist.
        foreach (['..%2F..%2F.env', '%2e%2e%2fbackups', urlencode($other->storage_path), '0 OR 1=1', "{$other->public_id}.sql.gz"] as $crafted) {
            $status = $this->postJson("/api/v1/backups/{$crafted}/restore-request")->getStatusCode();
            $this->assertContains($status, [404, 405], "{$crafted} answered {$status}");
        }

        $this->assertSame(0, BackupRestoreJob::query()->count());
        $this->assertTrue(Storage::disk('local')->exists($other->storage_path));
    }

    public function test_responses_never_carry_storage_locations_or_secrets(): void
    {
        config(['backup.encryption_key' => base64_encode(random_bytes(32))]);
        $store = Store::factory()->create();
        $backup = Backup::factory()->withArtifact()->create(['store_id' => $store->id]);
        $platform = $this->platformBackup();

        $responses = [
            $this->actingAs($this->ownerOf($store))->getJson('/api/v1/backups')->getContent(),
            $this->actingAs($this->platformStaff())->getJson('/api/v1/super-admin/backups')->getContent(),
            $this->getJson('/api/v1/super-admin/backups/summary')->getContent(),
            $this->postJson("/api/v1/super-admin/backups/{$platform->public_id}/verify")->getContent(),
        ];

        foreach ($responses as $content) {
            foreach (['storage_path', 'storage_disk', 'manifest', $backup->storage_path, $platform->storage_path, storage_path(), (string) config('backup.encryption_key'), 'password'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content);
            }
        }
    }

    public function test_a_store_is_told_a_backup_failed_but_not_why_in_technical_terms(): void
    {
        $store = Store::factory()->create();
        $reason = "mysqldump failed: Access denied for user 'app'@'10.0.3.7' (using password: YES)";
        Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Failed, 'failure_reason' => $reason]);

        $forStore = $this->actingAs($this->ownerOf($store))->getJson('/api/v1/backups')->assertOk();
        $this->assertSame('failed', $forStore->json('data.data.0.status'));
        $this->assertStringNotContainsString('10.0.3.7', $forStore->getContent());
        $this->assertStringNotContainsString('mysqldump', $forStore->getContent());
        $this->assertStringContainsString('did not complete', $forStore->json('data.data.0.failure_reason'));

        // Platform staff, on the platform surface, see the real reason.
        $forStaff = $this->actingAs($this->platformStaff())->getJson('/api/v1/super-admin/backups')->assertOk();
        $this->assertSame($reason, $forStaff->json('data.data.0.failure_reason'));
    }

    public function test_a_store_cannot_start_backups_in_a_loop(): void
    {
        $owner = $this->ownerOf(Store::factory()->create());

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($owner)->postJson('/api/v1/backups')->assertCreated();
        }

        $this->postJson('/api/v1/backups')->assertStatus(429);
        $this->assertSame(6, Backup::query()->count());
    }

    public function test_no_route_downloads_deletes_or_links_to_a_backup_artifact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'backup') || str_contains($route->uri(), 'restore'))
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())->sort()->values()->all();

        // Artifacts are never handed out: there is no download, no signed
        // URL and no delete endpoint, for a store or for the platform.
        // Deletion happens only through retention.
        $this->assertSame([
            'GET api/v1/backups',
            'GET api/v1/super-admin/backups',
            'GET api/v1/super-admin/backups/summary',
            'GET api/v1/super-admin/restore-jobs',
            'GET backups', // Phase B31: the store's Backups page (renders the page only; no file)
            'GET super-admin/backups',
            'POST api/v1/backups',
            'POST api/v1/backups/{backup}/restore-request',
            'POST api/v1/super-admin/backups',
            'POST api/v1/super-admin/backups/{backup}/rehearse',
            'POST api/v1/super-admin/backups/{backup}/verify',
            'POST api/v1/super-admin/restore-jobs/{backupRestoreJob}/authorize',
        ], $routes);

        // None of them is on the public storefront or the developer API.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'backup')) {
                $this->assertStringNotContainsString('storefront', $route->uri());
                $this->assertStringNotContainsString('api/dev', $route->uri());
                $this->assertNotContains('api_key.authenticate', $route->gatherMiddleware());
            }
        }
    }

    public function test_a_view_only_member_cannot_start_a_backup_or_ask_for_a_restore(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $store = Store::factory()->create();
        $manager = \App\Domain\Identity\Models\User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']); // backups.view only
        $staff = \App\Domain\Identity\Models\User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $this->systemRole($store, 'staff')->id, 'status' => 'active']); // no backup permission
        $backup = Backup::factory()->withArtifact()->create(['store_id' => $store->id]);

        $this->actingAs($manager)->getJson('/api/v1/backups')->assertOk();
        $this->postJson('/api/v1/backups')->assertStatus(403);
        $this->postJson("/api/v1/backups/{$backup->public_id}/restore-request")->assertStatus(403);

        $this->actingAs($staff)->getJson('/api/v1/backups')->assertStatus(403);

        $this->assertSame(1, Backup::query()->count());
        $this->assertSame(0, BackupRestoreJob::query()->count());
    }

    public function test_a_request_id_follows_a_backup_from_the_request_to_the_audit_trail(): void
    {
        $staff = $this->platformStaff();

        $response = $this->actingAs($staff)->postJson('/api/v1/super-admin/backups', [], ['X-Request-Id' => 'change-2026-10-14-0007'])->assertCreated();

        $backup = Backup::query()->where('public_id', $response->json('data.id'))->sole();
        $this->assertSame('change-2026-10-14-0007', $backup->request_id);
        $this->assertSame(BackupStatus::Verified, $backup->status);

        foreach (['backup.created', 'backup.completed', 'backup.verified'] as $action) {
            $this->assertSame('change-2026-10-14-0007', AuditLog::query()->where('action', $action)->sole()->request_id, $action);
        }
    }

    public function test_a_queued_rehearsal_does_nothing_for_a_backup_that_no_longer_exists(): void
    {
        // The job trusts nothing in its payload: it reads the backup again.
        (new RunRestoreRehearsalJob(424242, null))->handle(app(\App\Domain\DataProtection\Services\RestoreRehearsalService::class));

        $this->assertSame(0, BackupRestoreJob::query()->count());
    }
}
