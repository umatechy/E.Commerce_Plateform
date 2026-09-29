<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\HealthStatus;
use Carbon\CarbonImmutable;

/** A full store-health evaluation: every check plus the worst status among them. */
final class StoreHealthReport
{
    public readonly HealthStatus $overall;

    /** @param list<HealthCheckResult> $checks */
    public function __construct(
        public readonly int $storeId,
        public readonly array $checks,
        public readonly CarbonImmutable $checkedAt,
    ) {
        $this->overall = HealthStatus::worstOf(array_map(fn (HealthCheckResult $c) => $c->status, $checks));
    }

    /** @return list<array{key: string, status: string, message: string, metrics: array<string, mixed>}> */
    public function checksToArray(): array
    {
        return array_map(fn (HealthCheckResult $check) => $check->toArray(), $this->checks);
    }

    /** @return array{status: string, checked_at: string, checks: list<array{key: string, status: string, message: string, metrics: array<string, mixed>}>} */
    public function toArray(): array
    {
        return [
            'status' => $this->overall->value,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'checks' => $this->checksToArray(),
        ];
    }
}
