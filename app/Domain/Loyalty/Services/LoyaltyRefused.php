<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use RuntimeException;

/** A loyalty action that the rules do not allow, with a code the screens can use. */
final class LoyaltyRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
