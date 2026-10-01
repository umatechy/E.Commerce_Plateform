<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

/**
 * A well-formed team action that the rules do not allow, e.g. granting a
 * role beyond one's own authority or touching the owner. Answered 422
 * with the message under `field`, so the page can show it in place.
 */
final class TeamActionRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }
}
