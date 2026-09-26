<?php

declare(strict_types=1);

namespace App\Domain\Domains\Models;

/** Module 19 §19 "Domain Lifecycle" — the module's own exact 7-state list, used verbatim. */
enum DomainStatus: string
{
    case Pending = 'pending';
    case VerificationRequired = 'verification_required';
    case Verified = 'verified';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case Removed = 'removed';

    /** Module 19 §52 "Failure Handling" — only Active resolves live storefront traffic; nothing else, ever. */
    public function resolvesTraffic(): bool
    {
        return $this === self::Active;
    }
}
