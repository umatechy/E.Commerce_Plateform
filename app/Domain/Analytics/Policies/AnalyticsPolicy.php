<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Analytics\Models\ReportExport;

/**
 * Module 22 §35-36 "Role-Based Report Access / Sensitive Reports" —
 * financial reports get their OWN, stricter permission
 * (`analytics.financial`) than general dashboard/report viewing
 * (`analytics.view`), consistent with Module 22's own explicit
 * warning that revenue/payment data is sensitive.
 */
final class AnalyticsPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'analytics.view') || $this->isOwner($user);
    }

    public function viewFinancial(User $user): bool
    {
        return $this->userHasPermission($user, 'analytics.financial') || $this->isOwner($user);
    }

    public function export(User $user): bool
    {
        return $this->userHasPermission($user, 'analytics.export') || $this->isOwner($user);
    }

    public function downloadExport(User $user, ReportExport $export): bool
    {
        return $this->belongsToUsersActiveStore($user, $export) && $this->export($user);
    }
}
