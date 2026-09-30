<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

enum SupportPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function firstResponseHours(): int
    {
        return (int) config("support.sla.first_response_hours.{$this->value}");
    }

    public function resolutionHours(): int
    {
        return (int) config("support.sla.resolution_hours.{$this->value}");
    }
}
