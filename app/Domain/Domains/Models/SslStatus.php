<?php

declare(strict_types=1);

namespace App\Domain\Domains\Models;

/** Module 19 §17 "SSL/HTTPS" — status REPRESENTATION only, no real provisioning (see inspection findings). */
enum SslStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Active = 'active';
}
