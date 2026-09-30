<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Models;

/** Who performed an audited action. */
enum AuditActorType: string
{
    case User = 'user';          // store staff or platform staff (App\Domain\Identity\Models\User)
    case Customer = 'customer';  // storefront customer
    case ApiKey = 'api_key';     // Developer API credential (Module 31)
    case System = 'system';      // scheduler, queue worker, console
    case Anonymous = 'anonymous'; // unauthenticated request (e.g. a failed login)
}
