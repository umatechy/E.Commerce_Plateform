<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Support;

use Illuminate\Validation\Rule;

/**
 * Module 28 §36 — where a partner (a store's affiliate, B57; a platform
 * reseller, B58) is paid: a bank account or a JazzCash/Easypaisa mobile
 * account. Stored encrypted by the owning model; shown masked everywhere
 * except to someone paying, after step-up.
 */
final class PayoutAccount
{
    public const METHODS = ['bank_transfer', 'jazzcash', 'easypaisa'];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'method' => ['required', Rule::in(self::METHODS)],
            'account_title' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'bank' => ['nullable', 'required_if:method,bank_transfer', 'string', 'max:80'],
        ];
    }

    /**
     * What is stored: the number without spaces.
     *
     * @param array{account_title: string, account_number: string, bank?: ?string} $details
     * @return array{account_title: string, account_number: string, bank: ?string}
     */
    public static function normalize(array $details): array
    {
        return [
            'account_title' => trim($details['account_title']),
            'account_number' => (string) preg_replace('/\s+/', '', $details['account_number']),
            'bank' => $details['bank'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array{method: ?string, account_title: ?string, account: ?string, bank: ?string}
     */
    public static function masked(?string $method, ?array $details): array
    {
        $details = (array) $details;
        $number = (string) ($details['account_number'] ?? '');

        return [
            'method' => $method,
            'account_title' => $details['account_title'] ?? null,
            'account' => $number === '' ? null : str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4),
            'bank' => $details['bank'] ?? null,
        ];
    }

    /**
     * The full account, for whoever sends the money.
     *
     * @param array<string, mixed>|null $details
     * @return array{method: ?string, account_title: ?string, account_number: ?string, bank: ?string}
     */
    public static function full(?string $method, ?array $details): array
    {
        $details = (array) $details;

        return [
            'method' => $method,
            'account_title' => $details['account_title'] ?? null,
            'account_number' => $details['account_number'] ?? null,
            'bank' => $details['bank'] ?? null,
        ];
    }
}
