<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumpStrategy;

/** Shared set-up for the Phase B30 (gap G4) backup, restore and alert tests. */
trait InteractsWithBackups
{
    protected function setUpBackups(): void
    {
        Storage::fake('local');
        // The dump itself is faked here; MysqlRehearsalTest runs the real mysqldump.
        $this->app->bind(DatabaseDumpStrategy::class, FakeDatabaseDumpStrategy::class);
    }

    protected function platformStaff(array $attributes = []): User
    {
        return User::factory()->create(['platform_role' => 'support_agent', ...$attributes]);
    }

    protected function ownerOf(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** A verified platform backup whose artifact really is in (faked) storage. */
    protected function platformBackup(array $attributes = []): Backup
    {
        return Backup::factory()->withArtifact()->create(['scope' => BackupScope::Platform, 'store_id' => null, ...$attributes]);
    }

    /** Runs the outbox consumer for every pending event: alerts are sent from there. */
    protected function deliverEvents(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
    }

    /** @return Collection<int, NotificationMessage> the critical alerts recorded so far */
    protected function alerts(?string $eventType = null): Collection
    {
        return NotificationMessage::query()->withoutGlobalScopes()
            ->where('idempotency_key', 'like', 'alert:%')
            ->when($eventType !== null, fn ($q) => $q->where('source_event_type', $eventType))
            ->orderBy('id')->get();
    }

    /** Changes the stored artifact behind the application's back, as bit rot or tampering does. */
    protected function corrupt(Backup $backup): void
    {
        Storage::disk('local')->put($backup->storage_path, 'this is not the backup that was stored');
    }
}
