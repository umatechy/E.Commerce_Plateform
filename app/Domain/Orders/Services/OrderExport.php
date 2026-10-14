<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Services\CustomerExport;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Currencies;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase B51 (Module 03 §40 "Store Data Export"; Module 35 §42 "Export"
 * before deletion): every order, or every order line, as a CSV file, for
 * the merchant's own records — what tax and accounting need: number, dates,
 * statuses, customer, addresses, amounts and the items sold.
 *
 * Tenant-scoped by an explicit store id, audited, cells protected against
 * spreadsheet formulas (CustomerExport::safe), amounts in the currency's
 * own decimals, times in UTC.
 */
final class OrderExport
{
    private const ORDERS = ['order_number', 'created_at_utc', 'status', 'payment_status', 'fulfillment_status', 'source', 'customer_name', 'customer_email', 'customer_phone',
        'shipping_address', 'billing_address', 'currency', 'subtotal', 'discount', 'tax', 'shipping', 'store_credit', 'total', 'cancelled_at_utc', 'cancellation_reason'];

    private const LINES = ['order_number', 'created_at_utc', 'order_status', 'product', 'sku', 'variant', 'quantity', 'unit_price', 'discount', 'tax', 'line_total', 'currency'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function orders(int $storeId, User $actor): StreamedResponse
    {
        $this->audit->record('orders.exported', ['rows' => DB::table('orders')->where('store_id', $storeId)->count(), 'what' => 'orders'], storeId: $storeId, actor: $actor);

        return $this->stream('orders', self::ORDERS, function ($out) use ($storeId) {
            DB::table('orders')->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')->where('orders.store_id', $storeId)
                ->select('orders.*', 'customers.name as customer_name', 'customers.email as customer_email', 'customers.phone as customer_phone')
                ->orderBy('orders.id')->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $o) {
                        $c = (string) $o->currency;
                        fputcsv($out, array_map(CustomerExport::safe(...), [
                            (string) $o->order_number, self::time($o->created_at), (string) $o->status, (string) $o->payment_status, (string) $o->fulfillment_status, (string) $o->source,
                            (string) ($o->customer_name ?? $o->guest_name), (string) ($o->customer_email ?? $o->guest_email), (string) ($o->guest_phone ?? $o->customer_phone),
                            self::address($o->shipping_address_snapshot), self::address($o->billing_address_snapshot), $c,
                            Currencies::amount((int) $o->subtotal_minor, $c), Currencies::amount((int) $o->discount_total_minor, $c), Currencies::amount((int) $o->tax_total_minor, $c),
                            Currencies::amount((int) $o->shipping_total_minor, $c), Currencies::amount((int) $o->store_credit_minor, $c), Currencies::amount((int) $o->grand_total_minor, $c),
                            self::time($o->cancelled_at), (string) $o->cancellation_reason,
                        ]));
                    }
                });
        });
    }

    public function lines(int $storeId, User $actor): StreamedResponse
    {
        $this->audit->record('orders.exported', ['rows' => DB::table('order_items')->where('store_id', $storeId)->count(), 'what' => 'order_lines'], storeId: $storeId, actor: $actor);

        return $this->stream('order-lines', self::LINES, function ($out) use ($storeId) {
            DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('order_items.store_id', $storeId)
                ->select('order_items.*', 'orders.order_number', 'orders.created_at as order_created_at', 'orders.status as order_status', 'orders.currency')
                ->orderBy('order_items.id')->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $l) {
                        $c = (string) $l->currency;
                        $variant = json_decode((string) $l->variant_snapshot, true);
                        fputcsv($out, array_map(CustomerExport::safe(...), [
                            (string) $l->order_number, self::time($l->order_created_at), (string) $l->order_status, (string) $l->product_name_snapshot, (string) $l->sku_snapshot,
                            is_array($variant) ? implode(' / ', array_map('strval', array_filter($variant['options'] ?? $variant, 'is_scalar'))) : '',
                            (string) $l->quantity, Currencies::amount((int) $l->unit_price_minor, $c), Currencies::amount((int) $l->discount_minor, $c),
                            Currencies::amount((int) $l->tax_minor, $c), Currencies::amount((int) $l->line_total_minor, $c), $c,
                        ]));
                    }
                });
        });
    }

    /** @param list<string> $header */
    private function stream(string $name, array $header, \Closure $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($header, $rows) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read UTF-8
            fputcsv($out, $header);
            $rows($out);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => (string) RequestId::current(),
        ]);
    }

    private static function time(mixed $value): string
    {
        return $value === null ? '' : \Illuminate\Support\Carbon::parse((string) $value, 'UTC')->format('Y-m-d H:i:s');
    }

    private static function address(mixed $snapshot): string
    {
        $a = is_string($snapshot) ? json_decode($snapshot, true) : null;
        if (! is_array($a)) {
            return '';
        }

        return implode(', ', array_filter(array_map(fn ($k) => is_scalar($a[$k] ?? null) ? trim((string) $a[$k]) : '', ['name', 'line1', 'line2', 'city', 'province', 'postal_code', 'country', 'phone'])));
    }
}
