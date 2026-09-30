<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Models;

/** Module 24 — the outcome of one health check, and of a whole report. */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    /** @param iterable<self> $statuses */
    public static function worstOf(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
