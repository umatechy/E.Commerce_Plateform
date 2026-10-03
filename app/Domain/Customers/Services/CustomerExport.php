<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Settings\Services\ConfigService;
use App\Support\RequestId;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 10 §50: the customer list as a CSV file, with the filters of
 * the list. Permission-controlled (customers.export), tenant-scoped (the
 * directory query), audited (who, how many, which filters) and
 * data-minimized: contact details, standing, group, tags, consent and
 * order figures; no addresses, notes or account secrets.
 *
 * A cell that starts with =, +, - or @ is prefixed with ' so that a
 * spreadsheet shows it as text instead of running it as a formula.
 */
final class CustomerExport
{
    private const HEADER = ['id', 'name', 'email', 'phone', 'status', 'group', 'tags', 'marketing_email', 'account', 'orders', 'total_spent', 'currency', 'last_order_at_utc', 'created_at_utc'];

    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly AuditLogger $audit,
        private readonly ConfigService $config,
    ) {}

    /** @param array<string, mixed> $filters validated with CustomerDirectory::rules() */
    public function stream(array $filters, User $actor): StreamedResponse
    {
        $query = $this->directory->query($filters);
        $count = (clone $query)->toBase()->getCountForPagination();
        $currency = (string) $this->config->get('store.default_currency');

        $this->audit->record('customers.exported', ['rows' => $count, 'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '')], actor: $actor);

        return new StreamedResponse(function () use ($query, $currency) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read UTF-8
            fputcsv($out, self::HEADER);

            $query->chunk(500, function ($customers) use ($out, $currency) {
                foreach ($customers as $customer) {
                    /** @var Customer $customer */
                    fputcsv($out, array_map(self::safe(...), [
                        $customer->public_id,
                        $customer->name,
                        $customer->email,
                        (string) $customer->phone,
                        $customer->standing()->value,
                        (string) $customer->group?->name,
                        $customer->tags->pluck('name')->implode('; '),
                        $customer->marketing_email_opt_in ? 'yes' : 'no',
                        $customer->isRegistered() ? 'registered' : 'no account',
                        (string) (int) $customer->getAttribute('orders_count'),
                        self::amount((int) $customer->getAttribute('total_spent_minor'), $currency),
                        $currency,
                        $customer->getAttribute('last_order_at') ? \Illuminate\Support\Carbon::parse($customer->getAttribute('last_order_at'))->utc()->format('Y-m-d H:i:s') : '',
                        $customer->created_at?->utc()->format('Y-m-d H:i:s') ?? '',
                    ]));
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => (string) RequestId::current(),
        ]);
    }

    /** Neutralizes a value a spreadsheet would treat as a formula. */
    public static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** ISO 4217 currencies whose minor unit is not hundredths (ADR-003: never assume 2). */
    private const DIGITS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** Minor units as a plain decimal in the currency's own number of decimals. */
    public static function amount(int $minor, string $currency): string
    {
        $digits = self::DIGITS[strtoupper($currency)] ?? 2;

        return number_format($minor / (10 ** $digits), $digits, '.', '');
    }
}
