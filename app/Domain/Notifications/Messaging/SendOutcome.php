<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * A provider's answer to one message (Module 21 §28–29, §33).
 *
 * - accepted: the provider took it (delivery comes later, by webhook);
 * - failed + permanent: it will never go (bad number, template refused,
 *   credentials refused) — no retry;
 * - failed, not permanent: try again later (rate limit, provider down);
 * - unknown: no answer after sending — never retried, so a customer never
 *   gets the same message twice (§33).
 */
final class SendOutcome
{
    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $outcome,
        public readonly ?string $providerMessageId = null,
        public readonly bool $permanent = false,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(self::ACCEPTED, $providerMessageId);
    }

    public static function failed(string $code, string $message, bool $permanent): self
    {
        return new self(self::FAILED, null, $permanent, $code, mb_substr($message, 0, 255));
    }
}
