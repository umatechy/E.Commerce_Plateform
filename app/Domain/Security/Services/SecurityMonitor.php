<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Security\Models\SecurityEvent;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

/**
 * Phase B52 (Module 32 §58 "Security Monitoring", §59 "Security
 * Alerting"; Module 30 §58; b29 finding 6) — records what security
 * monitoring watches and raises an alert when a rule is met.
 *
 * Alerts go out through the existing critical alert channel (email to the
 * platform's operators, WhatsApp when set up): the outbox event
 * "security.alert", read by CriticalAlertNotifier. Every alert carries a
 * severity, the time, the source, the account or store concerned and what
 * to do (§59). Each rule alerts at most once per hour for the same account
 * or address, so an attack does not become a flood of email.
 *
 * Recording never stops the action it describes: a failure here is
 * reported and swallowed.
 */
final class SecurityMonitor
{
    /** type => default severity */
    public const TYPES = [
        'login_failed' => 'low',
        'login_locked' => 'medium',
        'mfa_failed' => 'low',
        'mfa_enabled' => 'info',
        'mfa_disabled' => 'medium',
        'mfa_reset' => 'high',
        'step_up_failed' => 'low',
        'password_changed' => 'info',
        'password_reset_requested' => 'info',
        'password_reset' => 'low',
        'sessions_revoked' => 'info',
        'session_timed_out' => 'info',
        'platform_role_changed' => 'high',
        'user_deactivated' => 'medium',
        'data_export' => 'info',
        'store_deletion_requested' => 'high',
        'restore_authorized' => 'high',
        'emergency_control_changed' => 'high',
        'incident_opened' => 'medium',
        'alert' => 'high',
    ];

    /** Failed sign-ins in 15 minutes before an alert: for one privileged account, from one address. */
    public const PRIVILEGED_FAILURES = 5;

    public const ADDRESS_FAILURES = 20;

    public function __construct(private readonly RecordsOutboxEvents $outbox) {}

    /** @param array<string, mixed> $context */
    public function record(string $type, ?User $user = null, ?string $subject = null, ?int $storeId = null, array $context = [], ?string $severity = null, ?string $ip = null): ?SecurityEvent
    {
        try {
            $event = SecurityEvent::query()->create([
                'type' => $type,
                'severity' => $severity ?? self::TYPES[$type] ?? 'info',
                'user_id' => $user?->id,
                'subject' => $subject === null ? null : mb_substr($subject, 0, 120),
                'store_id' => $storeId,
                'ip_address' => $ip ?? request()->ip(),
                'context' => $context === [] ? null : $context,
                'request_id' => RequestId::current(),
            ]);
            $this->evaluate($event, $user);

            return $event;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Phase B52 (Module 32 §58 "suspicious exports"): every data export; a large one raises an alert. */
    public function export(User $user, ?int $storeId, string $what, int $rows): void
    {
        $this->record('data_export', $user, $what, $storeId, ['what' => $what, 'rows' => $rows, 'mass' => $rows >= (int) config('security.monitoring.mass_export_rows')]);
    }

    /** An email address shown only in part (a***@example.com), for events about accounts that may not exist. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function evaluate(SecurityEvent $event, ?User $user): void
    {
        $who = $user !== null ? "{$user->name} <{$user->email}>".($user->isPlatformStaff() ? " (platform staff, {$user->platform_role})" : '') : ($event->subject ?? 'unknown account');

        match ($event->type) {
            'login_failed' => $this->failedSignIns($event, $user, $who),
            'mfa_disabled' => $user?->isPlatformStaff() === true
                ? $this->alert('mfa_disabled', "user:{$user->id}", 'high', 'Two-step sign-in turned off on a platform staff account', "{$who} turned off two-step sign-in.", 'If this was not the person themselves, deactivate the account and reset its password.', $event)
                : null,
            'mfa_reset' => $this->alert('mfa_reset', "user:{$event->user_id}:{$event->id}", 'high', 'Emergency two-step reset used', "Two-step sign-in was reset for {$who} by the server procedure.", 'Confirm with the account holder that they asked for it.', $event),
            'platform_role_changed' => $this->alert('platform_role_changed', "role:{$event->id}", 'high', 'Platform staff role changed', "{$who}: ".\App\Domain\SuperAdmin\Support\PlatformRoles::label($event->context['from'] ?? null).' → '.\App\Domain\SuperAdmin\Support\PlatformRoles::label($event->context['to'] ?? null).'. Changed by '.($event->context['by'] ?? 'unknown').'.', 'Check that this change was expected. Super admin rights should be held by very few people.', $event),
            'data_export' => ($event->context['mass'] ?? false) === true
                ? $this->alert('mass_export', "export:{$event->id}", 'medium', 'Large data export', "{$who} exported ".($event->context['rows'] ?? '?').' rows ('.($event->context['what'] ?? 'data').') of store '.($event->store_id ?? '?').'.', 'Check with the store that this export was expected.', $event)
                : null,
            'store_deletion_requested' => $this->alert('store_deletion', "store:{$event->store_id}:{$event->id}", 'high', 'Permanent store deletion requested', "Store {$event->subject} is scheduled for permanent deletion by {$who}.", 'Cancel it on the store page during the cooling period if it is not expected.', $event),
            'restore_authorized' => $this->alert('restore_authorized', "restore:{$event->id}", 'high', 'Production restore authorised', "{$who} authorised a production restore ({$event->subject}).", 'Make sure maintenance mode and the restore runbook are followed.', $event),
            'emergency_control_changed' => $this->alert('emergency_control', "control:{$event->id}", 'high', 'Emergency control changed', ($event->context['label'] ?? $event->subject).' turned '.(($event->context['on'] ?? false) ? 'on' : 'off')." by {$who}. Reason: ".($event->context['reason'] ?? '—'), 'Check that the change was intended and is undone when no longer needed.', $event),
            default => null,
        };
    }

    private function failedSignIns(SecurityEvent $event, ?User $user, string $who): void
    {
        $since = now()->subMinutes(15);
        if ($user?->isPlatformStaff() === true
            && SecurityEvent::query()->where('type', 'login_failed')->where('user_id', $user->id)->where('created_at', '>=', $since)->count() >= self::PRIVILEGED_FAILURES) {
            $this->alert('privileged_login_failures', "user:{$user->id}", 'high', 'Repeated failed sign-ins on a platform staff account',
                self::PRIVILEGED_FAILURES."+ failed sign-ins in 15 minutes for {$who}, last from {$event->ip_address}.", 'If it was not the person themselves, deactivate the account and check the addresses on the Security page.', $event);
        }
        if ($event->ip_address !== null
            && SecurityEvent::query()->where('type', 'login_failed')->where('ip_address', $event->ip_address)->where('created_at', '>=', $since)->count() >= self::ADDRESS_FAILURES) {
            $this->alert('address_login_failures', "ip:{$event->ip_address}", 'medium', 'Many failed sign-ins from one address',
                self::ADDRESS_FAILURES."+ failed sign-ins in 15 minutes from {$event->ip_address} (possible password guessing).", 'Sign-in is already slowed down per account. Block the address at the firewall if it continues.', $event);
        }
    }

    /** One alert per rule and key per hour. */
    private function alert(string $rule, string $key, string $severity, string $summary, string $what, string $action, SecurityEvent $source): void
    {
        $exists = SecurityEvent::query()->where('type', 'alert')->where('subject', "{$rule}:{$key}")->where('created_at', '>=', now()->subHour())->exists();
        if ($exists) {
            return;
        }
        DB::transaction(function () use ($rule, $key, $severity, $summary, $what, $action, $source) {
            $alert = SecurityEvent::query()->create([
                'type' => 'alert', 'severity' => $severity, 'user_id' => $source->user_id, 'subject' => mb_substr("{$rule}:{$key}", 0, 120),
                'store_id' => $source->store_id, 'ip_address' => $source->ip_address, 'request_id' => $source->request_id, 'alerted' => true,
                'context' => ['rule' => $rule, 'summary' => $summary, 'what' => $what, 'action' => $action, 'source_event_id' => $source->id],
            ]);
            $source->forceFill(['alerted' => true])->save();
            $this->outbox->recordEventFor(null, 'security.alert', [
                'rule' => $rule, 'severity' => $severity, 'summary' => $summary, 'what' => $what, 'action' => $action,
                'reference' => "SEC-{$alert->id}", 'request_id' => $source->request_id, 'reason' => $source->type,
            ], "security-alert:{$alert->id}");
        });
    }
}
