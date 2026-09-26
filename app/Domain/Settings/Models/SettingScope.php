<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

/** Module 33 §3 "Configuration Scope Model" — only Platform + Store are implemented (see docs/development/b17-inspection-findings.md "Scope Decision"). Store collapses Module 33's own separately-listed "Tenant Scope"/"Store Scope" into one, matching this platform's Store-IS-the-tenant model since Phase B1. */
enum SettingScope: string
{
    case Platform = 'platform';
    case Store = 'store';
}
