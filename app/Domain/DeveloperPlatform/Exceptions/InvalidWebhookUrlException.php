<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Exceptions;

use RuntimeException;

/** Module 31 §58-59 "SSRF / Webhook Security". */
final class InvalidWebhookUrlException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
