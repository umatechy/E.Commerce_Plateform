<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * What the shopper types at checkout for a provider. Two kinds exist:
 * the wallet's mobile number (03XXXXXXXXX) and, where the provider asks for
 * it, the last six digits of the wallet holder's CNIC. Neither is stored:
 * the attempt keeps only a masked hint (Module 12 §95).
 */
final class PayerField
{
    public const MOBILE = 'mobile';

    public const CNIC_LAST6 = 'cnic_last6';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $hint,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'hint' => $this->hint];
    }

    /** @return non-empty-string|null the normalized value, or null when it is not valid */
    public static function normalize(string $key, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[\s\-]/', '', $value) ?? '';
        if ($key === self::MOBILE) {
            // +92 3XX… and 92 3XX… are the same Pakistani mobile number as 03XX….
            $digits = preg_replace('/^(\+?92)(?=3)/', '0', $digits) ?? $digits;

            return preg_match('/^03\d{9}$/', $digits) === 1 ? $digits : null;
        }
        if ($key === self::CNIC_LAST6) {
            return preg_match('/^\d{6}$/', $digits) === 1 ? $digits : null;
        }

        return null;
    }

    /** 03001234567 → 0300•••4567 */
    public static function mask(string $mobile): string
    {
        return substr($mobile, 0, 4).'•••'.substr($mobile, -4);
    }
}
