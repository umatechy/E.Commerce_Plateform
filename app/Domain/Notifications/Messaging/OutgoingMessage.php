<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * One SMS or WhatsApp message as a provider needs it. `template` is set for
 * WhatsApp: business-initiated messages go out only as provider-approved
 * templates, their variables in the template's order (Module 21 §19, §37).
 * `statusCallbackUrl`: where the provider reports delivery, when it takes
 * one per message (Twilio).
 */
final class OutgoingMessage
{
    /** @param array{name: string, language: string, parameters: list<string>}|null $template */
    public function __construct(
        public readonly string $to,
        public readonly string $text,
        public readonly ?array $template = null,
        public readonly ?string $statusCallbackUrl = null,
        public readonly string $reference = '',
    ) {}
}
