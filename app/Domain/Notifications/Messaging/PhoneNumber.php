<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * Phase B50 — Module 21 §8 "Customer Contact Data": phone numbers in
 * international (E.164) form, which every SMS and WhatsApp provider takes.
 * Pakistan is the default country (owner decision 2026-10-03): 0300 1234567
 * becomes +923001234567. A number that cannot be read is refused — it is
 * never sent to a provider as typed.
 */
final class PhoneNumber
{
    /** @return non-empty-string|null "+923001234567", or null */
    public static function e164(?string $raw, string $defaultCountryCode = '92'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (! str_starts_with($digits, '+')) {
            // A national number: drop the leading 0 and add the country code.
            $digits = '+'.$defaultCountryCode.ltrim($digits, '0');
        }
        if (preg_match('/^\+[1-9]\d{7,14}$/', $digits) !== 1) {
            return null;
        }
        // Pakistani mobiles are +92 3XX XXXXXXX (10 digits after 92).
        if (str_starts_with($digits, '+92') && preg_match('/^\+923\d{9}$/', $digits) !== 1) {
            return null;
        }

        return $digits;
    }

    /** +923001234567 → +92300•••4567 (for screens and logs). */
    public static function mask(string $e164): string
    {
        return strlen($e164) > 8 ? substr($e164, 0, 6).'•••'.substr($e164, -4) : '•••';
    }
}
