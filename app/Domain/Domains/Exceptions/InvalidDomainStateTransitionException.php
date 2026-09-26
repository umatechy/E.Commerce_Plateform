<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use App\Domain\Domains\Models\DomainStatus;
use RuntimeException;

final class InvalidDomainStateTransitionException extends RuntimeException
{
    public function __construct(public readonly DomainStatus $from, public readonly DomainStatus $to)
    {
        parent::__construct("Cannot transition domain from [{$from->value}] to [{$to->value}].");
    }
}
