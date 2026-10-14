<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * One value a merchant account needs. Secret values are write-only: the API
 * returns only whether they are set (Module 12 §33; Module 30 §27 "never
 * displayed in full"). Non-secret values are shown masked.
 */
final class CredentialField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $secret,
        public readonly int $maxLength = 191,
        public readonly ?string $pattern = null,
        public readonly ?string $help = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'secret' => $this->secret, 'max_length' => $this->maxLength, 'help' => $this->help];
    }
}
