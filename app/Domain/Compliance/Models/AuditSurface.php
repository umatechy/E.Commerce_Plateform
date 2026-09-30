<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Models;

/**
 * Which entry point an audited action came through — ADR-002 requires
 * audit records to "clearly label which surface issued/used" a credential.
 */
enum AuditSurface: string
{
    case Staff = 'staff';               // Surface A: store admin SPA
    case SuperAdmin = 'super_admin';    // platform staff acting in platform or impersonation context
    case Customer = 'customer';         // storefront customer API
    case DeveloperApi = 'developer_api'; // Surface B: API keys
    case System = 'system';             // console, scheduler, queue
    case Public = 'public';             // unauthenticated HTTP
}
