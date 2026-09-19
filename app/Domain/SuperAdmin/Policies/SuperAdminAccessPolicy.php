<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Policies;

use App\Domain\Identity\Models\User;

/**
 * Module 30 boundary: platform-level authority, structurally separate
 * from every store-scoped Role/Permission (User::isPlatformStaff() reads
 * users.platform_role — a column no store-scoped Role can ever grant).
 * B1 scope: the minimum gate needed for the impersonation entry point
 * the B0 test suite already exercises. Fine-grained platform role
 * hierarchy (Module 30 §5) is deferred to Phase B24.
 */
final class SuperAdminAccessPolicy
{
    public function impersonate(User $user): bool
    {
        return $user->isPlatformStaff();
    }
}
