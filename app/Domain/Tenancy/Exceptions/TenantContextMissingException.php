<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped code runs before TenantContext was resolved.
 * Deliberately a hard failure (ADR-001): an unresolved context must never
 * be silently treated as "no tenant filter", since that would be a
 * tenant-isolation bypass.
 */
final class TenantContextMissingException extends RuntimeException
{
}
