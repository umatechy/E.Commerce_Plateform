<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

/** An affiliate program action the program's, affiliate's or payout's state does not allow (answered 422). */
final class AffiliateRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
