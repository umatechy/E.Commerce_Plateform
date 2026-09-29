<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\HealthStatus;

/** The outcome of one Module 24 store-health check. */
final class HealthCheckResult
{
    /** @param array<string, mixed> $metrics */
    public function __construct(
        public readonly string $key,
        public readonly HealthStatus $status,
        public readonly string $message,
        public readonly array $metrics = [],
    ) {}

    /** @return array{key: string, status: string, message: string, metrics: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'message' => $this->message,
            'metrics' => $this->metrics,
        ];
    }
}
