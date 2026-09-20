<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Exceptions;

use RuntimeException;

/**
 * Thrown for any rule referencing a non-whitelisted field/operator —
 * Non-Negotiable Rules #3-4: "No raw SQL supplied by tenant
 * administrators. No arbitrary expression evaluation."
 */
final class InvalidSegmentRuleException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
