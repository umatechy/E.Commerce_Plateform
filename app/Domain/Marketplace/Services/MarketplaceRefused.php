<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

/** A marketplace action the app's or installation's state does not allow (answered 422). */
final class MarketplaceRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
