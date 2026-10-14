<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * Decrypted merchant credentials for one call, plus the environment they
 * belong to. Kept out of every log and every serialisation: __debugInfo and
 * jsonSerialize show nothing secret (Module 12 §33, §58).
 */
final class ProviderCredentials implements \JsonSerializable
{
    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    /** @param array<string, string> $values */
    public function __construct(
        private readonly array $values,
        public readonly string $environment,
    ) {}

    public function get(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    public function isProduction(): bool
    {
        return $this->environment === self::PRODUCTION;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'values' => '[hidden]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
