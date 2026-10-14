<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Security\Services\SecurityMonitor;
use App\Domain\SuperAdmin\Support\PlatformRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B52 (Module 30 §3, §16; Module 32 §65) — who is Umar Techy staff,
 * and with which role.
 *
 * - Nobody changes their own role, and the last active super admin is
 *   never demoted, removed or deactivated (the platform must always have
 *   someone who can manage staff).
 * - Giving someone platform access at all happens only on the server
 *   (platform:staff), never from a screen, so a stolen admin session
 *   cannot create new platform staff.
 * - Every change is audited, raises a security alert, and ends the
 *   person's other sessions when their rights shrink.
 */
final class PlatformStaff
{
    public function __construct(private readonly AuditLogger $audit, private readonly SecurityMonitor $monitor) {}

    public static function activeSuperAdmins(): int
    {
        return User::query()->where('platform_role', 'super_admin')->where('is_active', true)->count();
    }

    /** @param ?string $role null removes platform access */
    public function changeRole(User $target, ?string $role, string $reason, ?User $actor, string $by): User
    {
        if ($role !== null && ! isset(PlatformRoles::ROLES[$role])) {
            throw ValidationException::withMessages(['role' => 'Choose one of the platform roles.']);
        }
        if ($actor !== null && $actor->id === $target->id) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own platform role.']);
        }

        return DB::transaction(function () use ($target, $role, $reason, $actor, $by) {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $from = PlatformRoles::normalize($locked->platform_role);
            if ($from === $role) {
                return $locked;
            }
            if ($from === 'super_admin' && $locked->is_active && self::activeSuperAdmins() <= 1) {
                throw ValidationException::withMessages(['role' => 'This is the last active super admin. Make someone else super admin first.']);
            }
            $locked->forceFill(['platform_role' => $role])->save();
            $this->audit->record('platform.staff_role_changed', ['target' => $locked->public_id, 'from' => $from, 'to' => $role, 'reason' => $reason, 'by' => $by], $locked, actor: $actor, platform: true);
            $this->monitor->record('platform_role_changed', $locked, context: ['from' => $from, 'to' => $role, 'by' => $by, 'reason' => $reason]);
            $shrinks = $role === null || count(PlatformRoles::capabilitiesOf($role)) < count(PlatformRoles::capabilitiesOf($from));
            if ($shrinks) {
                app(\App\Domain\Identity\Services\UserSessions::class)->revokeAll($locked, 'revoked', $actor);
            }

            return $locked;
        });
    }
}
