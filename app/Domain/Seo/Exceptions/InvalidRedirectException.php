<?php

declare(strict_types=1);

namespace App\Domain\Seo\Exceptions;

use RuntimeException;

final class InvalidRedirectException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
