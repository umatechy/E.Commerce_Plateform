<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Billing\Services\BillingContact;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreDeletion;

/**
 * Phase B51 (Module 03 §33–37; Module 21 administrative messages): tells
 * the store's owner, by email, what happened to their store and what it
 * means for them — with the dates that matter. Administrative messages:
 * never subject to marketing preferences. One email per change
 * (idempotency key from the outbox event's own key).
 */
final class StoreLifecycleNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param array<string, mixed> $payload */
    public function route(array $payload): void
    {
        $action = (string) ($payload['action'] ?? '');
        $store = Store::query()->withTrashed()->find((int) ($payload['store_id'] ?? 0));
        $owner = $store === null ? null : app(BillingContact::class)->ownerOf($store->id);
        if ($store === null || $owner === null) {
            return; // no owner left to tell (e.g. after a deletion)
        }
        $until = $store->retention_ends_at?->toFormattedDateString() ?? '';
        $deletion = isset($payload['deletion_id']) ? StoreDeletion::query()->find((int) $payload['deletion_id']) : null;

        [$subject, $body] = match ($action) {
            'close' => ['{{store.name}} is closed',
                "Hi {{owner.name}}, {{store.name}} is now closed. Its shop is no longer open to customers and no new invoices will be issued. Your data is kept until {{retention.until}}: until then you can download it and reopen the store from Settings → Close store. After that date the store is archived and can no longer be reopened by you."],
            'reopen' => ['{{store.name}} is open again',
                'Hi {{owner.name}}, {{store.name}} has been reopened. Billing restarts from today; check Billing for your next invoice.'],
            'suspend' => ['{{store.name}} has been suspended',
                'Hi {{owner.name}}, Umar Techy has suspended {{store.name}}. Its shop is closed to customers and nothing can be changed in the admin. You can still see your data, download it and contact Umar Techy support from the admin.'],
            'reactivate' => ['{{store.name}} is active again',
                'Hi {{owner.name}}, the suspension of {{store.name}} has been lifted.'],
            'retention_reminder' => ['Reminder: {{store.name}} will be archived on {{retention.until}}',
                'Hi {{owner.name}}, {{store.name}} is closed. On {{retention.until}} it will be archived: you will no longer be able to reopen it or download its data. Download what you need, or reopen the store, from Settings → Close store before then.'],
            'archive' => ['{{store.name}} has been archived',
                'Hi {{owner.name}}, the period to reopen {{store.name}} has ended and the store is now archived. Contact Umar Techy support if you need anything from it.'],
            'restore' => ['{{store.name}} can be reopened again',
                'Hi {{owner.name}}, Umar Techy has brought {{store.name}} back from the archive. You can download its data or reopen it from Settings → Close store until {{retention.until}}.'],
            'deletion_requested' => ['{{store.name}} will be permanently deleted',
                'Hi {{owner.name}}, the data of {{store.name}} is scheduled for permanent deletion, not before {{deletion.date}}. Billing records are kept as the law requires. Contact Umar Techy support before that date if this is a mistake.'],
            default => [null, null],
        };
        if ($subject === null || $body === null) {
            return;
        }

        $this->notifications->send(
            NotificationMessageType::Administrative, NotificationChannel::Email, RecipientType::User, $owner->id, $owner->email,
            $subject, $body,
            ['owner.name' => $owner->name, 'store.name' => $store->name, 'retention.until' => $until, 'deletion.date' => $deletion?->not_before->toFormattedDateString() ?? ''],
            'notification:store-lifecycle:'.$store->id.':'.$action.':'.($payload['event_id'] ?? $deletion->public_id ?? $store->retention_ends_at?->toDateString() ?? ''),
            'store.lifecycle_changed',
        );
    }
}
