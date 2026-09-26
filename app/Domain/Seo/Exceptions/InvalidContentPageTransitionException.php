<?php

declare(strict_types=1);

namespace App\Domain\Seo\Exceptions;

use App\Domain\Seo\Models\ContentPageStatus;
use RuntimeException;

final class InvalidContentPageTransitionException extends RuntimeException
{
    public function __construct(public readonly ContentPageStatus $from, public readonly ContentPageStatus $to)
    {
        parent::__construct("Cannot transition content page from [{$from->value}] to [{$to->value}].");
    }
}
