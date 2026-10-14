<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * One thing a provider told us in a webhook (Module 21 §35): a delivery
 * report for a message we sent (sent, delivered, read, failed), or a
 * message a customer sent us (used for STOP / START). `key` identifies it
 * at the provider so it is applied once.
 */
final class ProviderEvent
{
    public const STATUS = 'status';

    public const INBOUND = 'inbound';

    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $status = null, // sent | delivered | read | failed
        public readonly ?string $errorCode = null,
        public readonly ?string $errorText = null,
        public readonly ?string $from = null,
        public readonly ?string $text = null,
    ) {}
}
