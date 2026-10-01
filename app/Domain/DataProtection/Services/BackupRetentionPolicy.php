<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Models\BackupRetentionTier;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Support\Carbon;

/**
 * The one place a retention tier becomes an expiry date (Module 23 §29).
 * Durations are platform settings (Module 33), never constants in the
 * business logic: `backup.retention_days` (daily, manual and pre-change
 * backups; default 30) and `backup.monthly_retention_months` (default 12).
 */
final class BackupRetentionPolicy
{
    public function __construct(private readonly ConfigService $config) {}

    public function expiresAt(BackupRetentionTier $tier, Carbon $from): Carbon
    {
        return $tier === BackupRetentionTier::Monthly
            ? $from->copy()->addMonthsNoOverflow((int) $this->config->get('backup.monthly_retention_months'))
            : $from->copy()->addDays((int) $this->config->get('backup.retention_days'));
    }

    /**
     * The scheduled backup for a day: the first of a month is that month's
     * monthly backup, every other day a daily one.
     *
     * @return array{tier: BackupRetentionTier, key: string}
     */
    public function scheduledSlot(Carbon $day): array
    {
        return $day->day === 1
            ? ['tier' => BackupRetentionTier::Monthly, 'key' => 'platform:monthly:'.$day->format('Y-m')]
            : ['tier' => BackupRetentionTier::Daily, 'key' => 'platform:daily:'.$day->format('Y-m-d')];
    }
}
