<?php

declare(strict_types=1);

namespace App\Domain\Help\Services;

/** A help center action the article's state or content does not allow (answered 422). */
final class HelpActionRefused extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
