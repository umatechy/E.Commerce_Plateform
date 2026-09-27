<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Models\ApiKeyStatus;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The ONLY code path that creates a DeveloperApplication or mutates its `status`. */
final class ApplicationService
{
    public function __construct(private readonly RecordsOutboxEvents $outbox) {}

    public function create(Store $store, string $name, ?int $createdByUserId): DeveloperApplication
    {
        $application = DeveloperApplication::query()->create([
            'public_id' => (string) Str::ulid(),
            'store_id' => $store->id,
            'name' => $name,
            'status' => ApplicationStatus::Active,
            'created_by_user_id' => $createdByUserId,
        ]);

        $this->outbox->recordEvent(
            eventType: 'developer.application.created',
            payload: ['application_id' => $application->id, 'store_id' => $store->id],
            idempotencyKey: "developer_application:{$application->id}:created",
        );

        return $application;
    }

    public function suspend(DeveloperApplication $application): DeveloperApplication
    {
        $application->update(['status' => ApplicationStatus::Suspended]);

        $this->outbox->recordEvent(
            eventType: 'developer.application.suspended',
            payload: ['application_id' => $application->id, 'store_id' => $application->store_id],
            idempotencyKey: "developer_application:{$application->id}:suspended:".now()->timestamp,
        );

        return $application->fresh();
    }

    public function reactivate(DeveloperApplication $application): DeveloperApplication
    {
        $application->update(['status' => ApplicationStatus::Active]);

        return $application->fresh();
    }

    /**
     * Module 31 §39 "Application Lifecycle" — revoking an application
     * MUST immediately prevent new access (§2.28) — every one of its
     * own API keys is revoked in the SAME transaction, not left active
     * under a "revoked" parent.
     */
    public function revoke(DeveloperApplication $application): DeveloperApplication
    {
        return DB::transaction(function () use ($application) {
            $application->update(['status' => ApplicationStatus::Revoked]);
            $application->apiKeys()->where('status', ApiKeyStatus::Active)->update(['status' => ApiKeyStatus::Revoked, 'revoked_at' => now()]);

            $this->outbox->recordEvent(
                eventType: 'developer.application.revoked',
                payload: ['application_id' => $application->id, 'store_id' => $application->store_id],
                idempotencyKey: "developer_application:{$application->id}:revoked:".now()->timestamp,
            );

            return $application->fresh();
        });
    }
}
