<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

/**
 * Unknown, wrong-token, expired, revoked or used invitation. Deliberately
 * one exception and one message, so the answer never tells an attacker
 * which of those it was.
 */
final class InvitationUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This invitation link is invalid or has expired. Ask the store to send a new one.');
    }
}
