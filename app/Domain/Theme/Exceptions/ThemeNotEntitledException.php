<?php

declare(strict_types=1);

namespace App\Domain\Theme\Exceptions;

use RuntimeException;

/** Phase B36: the configuration uses something the store's package does not include (Module 17 §41). */
final class ThemeNotEntitledException extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', $violations).' Upgrade your package, or choose something your package includes.');
    }
}
