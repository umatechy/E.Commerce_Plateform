<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

/**
 * The platform's currencies (owner decision 2026-10-03).
 *
 * - The platform is built for Pakistan first: a new store's currency is
 *   PKR, shown as "Rs.".
 * - Five more are offered for later use outside Pakistan: USD, EUR, GBP,
 *   AED and SAR. Platform staff may change the list
 *   (`platform.supported_currencies`); a store chooses one of them.
 * - Amounts are integers in the currency's minor unit (ADR-003), with
 *   the ISO 4217 number of decimals. PKR has 2 (paisa) in ISO 4217, even
 *   though browsers display it without decimals; every conversion here
 *   and in resources/js/lib/money.ts uses ISO, never the browser's
 *   display rule, so 1999 minor units are always Rs. 19.99.
 * - Nothing is ever converted between currencies.
 */
final class Currencies
{
    public const DEFAULT = 'PKR';

    /** The default list of `platform.supported_currencies`, the store default first. */
    public const SUPPORTED = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'];

    /** ISO 4217 currencies whose minor unit is not hundredths (ADR-003: never assume 2). */
    private const DIGITS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** How many decimals the currency's minor unit has (PKR, USD: 2; JPY: 0; KWD: 3). */
    public static function digits(string $currency): int
    {
        return self::DIGITS[strtoupper($currency)] ?? 2;
    }

    /** Minor units as a plain decimal in the currency's own number of decimals ("1234.50"). */
    public static function amount(int $minor, string $currency): string
    {
        $digits = self::digits($currency);

        return number_format($minor / (10 ** $digits), $digits, '.', '');
    }

    /** Minor units for people to read, with thousands separators ("1,234.50"). */
    public static function readable(int $minor, string $currency): string
    {
        $digits = self::digits($currency);

        return number_format($minor / (10 ** $digits), $digits);
    }

    /** @return list<string> the currencies the platform offers now (`platform.supported_currencies`) */
    public static function supported(): array
    {
        return array_values((array) app(ConfigService::class)->get('platform.supported_currencies'));
    }

    /**
     * Validation rule for a currency a store or platform staff enter:
     * one of the supported currencies. Upper-case the input first.
     */
    public static function rule(): \Illuminate\Validation\Rules\In
    {
        return \Illuminate\Validation\Rule::in(self::supported());
    }

    /** A three-letter ISO 4217-shaped code. */
    public static function isCode(string $code): bool
    {
        return preg_match('/^[A-Z]{3}$/', $code) === 1;
    }
}
