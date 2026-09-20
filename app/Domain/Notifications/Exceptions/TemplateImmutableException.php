<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use RuntimeException;

final class TemplateImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This template has been published and cannot be modified. Create a new template instead.');
    }
}
