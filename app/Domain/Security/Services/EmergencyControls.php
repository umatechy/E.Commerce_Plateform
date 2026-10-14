<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Models\SettingRevision;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;

/**
 * Phase B52 (Module 30 §60 "Emergency Controls", §61 "Kill Switch", §128
 * "Platform Lockdown") — the platform-wide switches for an incident, in one
 * place, each with its impact spelled out, a reason, step-up, the audit
 * trail and a security alert. They are ordinary platform settings (with
 * their history and rollback), managed only here (not on Platform
 * settings), so nobody changes one without seeing what it does.
 *
 * Narrower controls stay where they live: suspending a store (Store life),
 * closing a payment or SMS provider for real use, suspending a developer
 * application, deactivating a user.
 */
final class EmergencyControls
{
    /** setting => [label, what "safe" (normal) is, impact when switched to the emergency value, the emergency value] */
    public const CONTROLS = [
        'platform.maintenance_mode' => ['Maintenance mode', false, 'Every storefront shows a maintenance page and takes no orders. Store admins and the Super Admin keep working.', true],
        'platform.self_signup_enabled' => ['New store sign-ups', true, 'Nobody can create a store from the sign-up page. Stores Umar Techy creates are not affected.', false],
        'platform.developer_api_enabled' => ['Developer API', true, 'Every API key of every store stops working at once (503). Store admins and storefronts are not affected.', false],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly SecurityMonitor $monitor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        return array_map(function (string $key) {
            [$label, $normal, $impact, $emergency] = self::CONTROLS[$key];
            $value = (bool) $this->config->get($key);
            $last = app(TenantContext::class)->asPlatform(fn () => SettingRevision::query()->where('key', $key)->orderByDesc('id')->first());

            return [
                'key' => $key, 'label' => $label, 'value' => $value, 'normal' => $normal, 'emergency' => $value === $emergency && $value !== $normal,
                'impact' => $impact, 'emergency_value' => $emergency,
                'changed_at' => $last?->created_at?->toIso8601String(), 'changed_reason' => $last?->reason,
                'changed_by' => $last?->changed_by_user_id !== null ? User::query()->find($last->changed_by_user_id)?->email : null,
            ];
        }, array_keys(self::CONTROLS));
    }

    public function set(string $key, bool $value, string $reason, User $actor): void
    {
        abort_unless(isset(self::CONTROLS[$key]), 404);
        if ((bool) $this->config->get($key) === $value) {
            return;
        }
        $this->config->set($key, $value, SettingScope::Platform, $actor->id, $reason);
        $this->audit->record('security.emergency_control', ['key' => $key, 'value' => $value, 'reason' => $reason], actor: $actor, platform: true);
        $this->monitor->record('emergency_control_changed', $actor, $key, context: ['label' => self::CONTROLS[$key][0], 'on' => $value, 'reason' => $reason]);
    }
}
