<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use RuntimeException;

final class DomainNotEligibleForPrimaryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only a verified or active domain can be set as primary.');
    }
}
