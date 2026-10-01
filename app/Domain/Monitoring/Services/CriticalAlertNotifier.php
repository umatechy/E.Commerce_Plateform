<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Critical operational alerts (SRS HEALTH-005; Module 23 "Backup
 * Failure Alerts"; owner decision 2026-09-30 §4).
 *
 * It is not a second notification system. A domain records an outbox
 * event (ADR-004) as it always did; NotificationEventRouter hands the
 * critical ones here; this class only decides who is told and in which
 * words, and sends through NotificationService — the same queue,
 * retries, delivery log and audit as every other message.
 *
 * Channels: email always. WhatsApp when `alerts.whatsapp_enabled` is on
 * and recipients are set. No WhatsApp provider is connected yet (gap
 * G10), so such a message is recorded as failed with
 * "channel_not_configured" — visibly, not silently.
 *
 * Recipients: `alerts.critical_email_recipients`. While that list is
 * empty, every active platform staff account. No address is built in.
 *
 * Deduplication: one message per event, channel and recipient, keyed on
 * the event's own identity (which backup, which restore, which day).
 * A retried or re-consumed event sends nothing new. A different backup
 * or another day is a different alert.
 *
 * Alerts are platform records. They are written in platform context,
 * so a store never sees them or the staff addresses in them.
 */
final class CriticalAlertNotifier
{
    public const EVENTS = [
        'backup.failed',
        'backup.verification_failed',
        'backup.overdue',
        'backup.repeated_failure',
        'backup.retention_cleanup_failed',
        'restore.failed',
        'restore.rehearsal_failed',
    ];

    private const BODY = "{{alert.summary}}\n\n"
        ."What: {{alert.what}}\n"
        ."Reason: {{alert.reason}}\n"
        ."When: {{alert.at}} UTC\n"
        ."Reference: {{alert.reference}}\n"
        ."Request ID: {{alert.request_id}}\n\n"
        ."What to do: {{alert.action}}\n"
        .'Procedure: docs/security/incident-response-runbook.md — "{{alert.runbook}}".';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ConfigService $config,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload the outbox event's payload */
    public function route(string $eventType, array $payload): void
    {
        $alert = $this->describe($eventType, $payload);

        if ($alert === null) {
            return;
        }

        $this->context->asPlatform(function () use ($eventType, $payload, $alert) {
            $variables = [
                'alert.summary' => $alert['summary'],
                'alert.what' => $alert['what'],
                'alert.reason' => (string) ($payload['reason'] ?? 'not recorded'),
                'alert.at' => now('UTC')->format('Y-m-d H:i'),
                'alert.reference' => $alert['reference'],
                'alert.request_id' => (string) ($payload['request_id'] ?? 'none'),
                'alert.action' => $alert['action'],
                'alert.runbook' => $alert['runbook'],
            ];

            $sent = 0;

            foreach ($this->emailRecipients() as $recipient) {
                $sent += $this->send(NotificationChannel::Email, $recipient['id'], $recipient['address'], $eventType, $alert, $variables);
            }

            if ($this->config->get('alerts.whatsapp_enabled') === true) {
                foreach ((array) $this->config->get('alerts.whatsapp_recipients') as $number) {
                    $sent += $this->send(NotificationChannel::WhatsApp, null, (string) $number, $eventType, $alert, $variables);
                }
            }

            // Also in the application log, so an alert never depends on
            // message delivery alone.
            Log::critical("Critical alert: {$alert['subject']}", ['event' => $eventType, 'reference' => $alert['reference'], 'reason' => $variables['alert.reason']]);

            if ($sent > 0) {
                $this->audit->record('alert.raised', [
                    'event' => $eventType, 'reference' => $alert['reference'], 'messages' => $sent,
                    // The request or run that caused the event: this entry is written later, by the outbox consumer.
                    'source_request_id' => $payload['request_id'] ?? null,
                ], platform: true);
            }
        });
    }

    /**
     * @param array{subject: string, summary: string, what: string, reference: string, action: string, runbook: string} $alert
     * @param array<string, string> $variables
     * @return int 1 when a new message was created, 0 when this alert had already been sent to this recipient
     */
    private function send(NotificationChannel $channel, ?int $userId, string $destination, string $eventType, array $alert, array $variables): int
    {
        $key = "alert:{$eventType}:{$alert['reference']}:{$channel->value}:".sha1(mb_strtolower($destination));

        // Already sent to this recipient for this very incident: a retried
        // or re-consumed event. (Platform context: the lookup is unscoped.)
        if (NotificationMessage::query()->where('idempotency_key', $key)->exists()) {
            return 0;
        }

        $this->notifications->send(
            NotificationMessageType::System, $channel, RecipientType::User, $userId, $destination,
            '[CRITICAL] '.$alert['subject'],
            // Email is sent as HTML: keep the lines apart there.
            $channel === NotificationChannel::Email ? nl2br(self::BODY) : self::BODY,
            $variables, $key, $eventType,
        );

        return 1;
    }

    /** @return list<array{id: ?int, address: string}> */
    private function emailRecipients(): array
    {
        $configured = array_values(array_filter((array) $this->config->get('alerts.critical_email_recipients')));

        if ($configured !== []) {
            return array_map(fn (string $address) => ['id' => null, 'address' => $address], $configured);
        }

        return User::query()->whereNotNull('platform_role')->where('is_active', true)->orderBy('id')->get(['id', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'address' => $user->email])->all();
    }

    /**
     * @param array<string, mixed> $payload
     * @return ?array{subject: string, summary: string, what: string, reference: string, action: string, runbook: string}
     */
    private function describe(string $eventType, array $payload): ?array
    {
        $backup = (string) ($payload['backup_public_id'] ?? $payload['backup_id'] ?? 'unknown');
        $scope = ($payload['scope'] ?? 'platform') === 'store' ? 'store' : 'platform';

        return match ($eventType) {
            'backup.failed' => match ($payload['stage'] ?? null) {
                'store' => $this->alert('Backup storage failure', "A {$scope} backup could not be written to backup storage.", "Backup {$backup}", "backup:{$backup}",
                    'Check that backup storage is reachable and has free space, then run the backup again.', 'Backup failure'),
                'verify' => $this->alert('Backup verification failed', "A {$scope} backup was written but did not pass verification. It will not be used for a restore.", "Backup {$backup}", "backup:{$backup}",
                    'Check backup storage, then run the backup again.', 'Backup corruption'),
                default => $this->alert(($payload['trigger'] ?? null) === 'scheduled' ? 'Scheduled backup failed' : 'Backup failed', "A {$scope} backup did not complete.", "Backup {$backup}", "backup:{$backup}",
                    'Find the cause from the reason above, fix it, then run the backup again.', 'Backup failure'),
            },
            'backup.verification_failed' => $this->alert('Stored backup is damaged or missing', "A {$scope} backup that was good when it was made no longer matches its recorded checksum. It has been marked failed.", "Backup {$backup}", "backup-recheck:{$backup}",
                'Treat as possible storage failure or tampering. Take a new backup now.', 'Backup corruption'),
            'backup.overdue' => $this->alert('No recent backup', 'There is no verified platform backup within the recovery point target of '.(int) ($payload['rpo_target_hours'] ?? 0).' hours.', 'Last verified backup: '.($payload['last_verified_at'] ?? 'never'), 'overdue:'.now('UTC')->toDateString(),
                'Check that the scheduler and the queue worker are running, then run a backup.', 'Backup failure'),
            'backup.repeated_failure' => $this->alert('Backups keep failing', 'The last '.(int) ($payload['consecutive_failures'] ?? 0).' scheduled backups all failed.', 'Scheduled platform backups', 'repeated:'.now('UTC')->toDateString(),
                'The platform has no fresh recovery point. Find and fix the cause today.', 'Repeated backup failure'),
            'backup.retention_cleanup_failed' => $this->alert('Backup cleanup failed', (int) ($payload['failed'] ?? 0).' expired backup(s) could not be deleted.', 'Backups: '.implode(', ', array_slice((array) ($payload['backup_public_ids'] ?? []), 0, 5)), 'retention:'.now('UTC')->toDateString(),
                'Check backup storage permissions. The next cleanup run tries again.', 'Backup failure'),
            'restore.failed' => $this->alert('PRODUCTION RESTORE FAILED', 'A production restore did not complete. The live database may be partly restored.', "Restore job {$payload['restore_job_id']} from backup {$backup}; safety backup ".($payload['pre_restore_backup_public_id'] ?? 'unknown'), "restore:{$payload['restore_job_id']}",
                'Do not retry blindly. Keep the platform in maintenance and follow the runbook. There is no automatic rollback.', 'Production restore'),
            'restore.rehearsal_failed' => $this->alert('Restore rehearsal failed', 'A backup could not be restored in the rehearsal. Live data was not touched.', "Rehearsal {$payload['restore_job_id']} of backup {$backup}", "rehearsal:{$payload['restore_job_id']}",
                'The backup may not be restorable. Take a new backup and rehearse it.', 'Restore rehearsal failure'),
            default => null,
        };
    }

    /** @return array{subject: string, summary: string, what: string, reference: string, action: string, runbook: string} */
    private function alert(string $subject, string $summary, string $what, string $reference, string $action, string $runbook): array
    {
        return compact('subject', 'summary', 'what', 'reference', 'action', 'runbook');
    }
}
