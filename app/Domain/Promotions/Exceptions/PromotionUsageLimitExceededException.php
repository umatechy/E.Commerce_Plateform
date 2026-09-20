<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Exceptions;

use RuntimeException;

final class PromotionUsageLimitExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This promotion has reached its usage limit.');
    }
}
