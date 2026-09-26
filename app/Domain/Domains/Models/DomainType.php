<?php

declare(strict_types=1);

namespace App\Domain\Domains\Models;

/** Module 19 §4 "Domain Types" — only the 2 types B14 implements (see docs/development/b14-inspection-findings.md "Scope Decision"). */
enum DomainType: string
{
    case PlatformSubdomain = 'platform_subdomain';
    case CustomDomain = 'custom_domain';
}
