<?php

declare(strict_types=1);

namespace App\Domain\Security\Console;

use App\Domain\Identity\Models\UserSession;
use App\Domain\Security\Models\SecurityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase B52 (Module 32 §42 data retention, §24 data minimisation): security
 * monitoring keeps what it needs, not forever. Events older than
 * security.monitoring.events_retention_days, sessions ended more than 90
 * days ago and used or expired reset links are removed. The audit trail
 * is not touched: it is the permanent record.
 */
final class PruneSecurityRecordsCommand extends Command
{
    protected $signature = 'security:prune';

    protected $description = 'Remove old security events, ended sessions and spent password reset links.';

    public function handle(): int
    {
        $events = SecurityEvent::query()->where('created_at', '<', now()->subDays(max(30, (int) config('security.monitoring.events_retention_days'))))->delete();
        $sessions = UserSession::query()->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays(90))->delete();
        $resets = DB::table('staff_password_resets')->where(fn ($q) => $q->whereNotNull('used_at')->orWhere('expires_at', '<', now()))->where('created_at', '<', now()->subDays(7))->delete();
        $this->info("Removed {$events} events, {$sessions} ended sessions, {$resets} reset links.");

        return self::SUCCESS;
    }
}
