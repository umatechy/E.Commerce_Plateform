<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 09 §4 "Order Entity". Deliberately "dumb" like B4's Inventory
 * model — status transitions and totals are set ONLY by OrderService/
 * OrderStateMachine, never by direct `$order->update(['status' => ...])`
 * calls scattered through controllers (Module 09 Final Rule #11:
 * "confirmed orders must not be casually mutated").
 */
final class Order extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'orders';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'pending_confirmation',
        'payment_status' => 'unpaid',
        'fulfillment_status' => 'unfulfilled',
        'return_status' => 'none',
        'source' => 'storefront',
    ];

    protected $fillable = [
        'store_id', 'order_number', 'customer_id',
        'guest_name', 'guest_email', 'guest_phone',
        'status', 'payment_status', 'fulfillment_status', 'source',
        'currency', 'subtotal_minor', 'discount_total_minor', 'tax_total_minor',
        'shipping_total_minor', 'grand_total_minor',
        'billing_address_snapshot', 'shipping_address_snapshot',
        'notes', 'idempotency_key',
        'cancellation_reason', 'cancellation_note', 'cancelled_by',
        'completed_at', 'cancelled_at',
        'return_status', 'replacement_for_order_id', // Phase B33; written only by OrderService
        'store_credit_minor', // Phase B34; written only by OrderService::applyStoreCredit()
        'prices_include_tax', 'tax_snapshot', // Phase B46: the tax result used, kept as it was (Module 29 §58)
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'return_status' => OrderReturnStatus::class, // Module 09 §47 (Phase B33)
            'source' => OrderSource::class,
            'cancellation_reason' => CancellationReason::class,
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'prices_include_tax' => 'boolean',
            'tax_snapshot' => 'array',
        ];
    }

    /**
     * Phase B46: the tax as it was charged on this order, for every screen
     * and document that shows it (null: tax was off for the store).
     *
     * @return array{label: string, prices_include_tax: bool, exempt: bool, exemption_reference: ?string, shipping_tax_minor: int, breakdown: list<array<string, mixed>>}|null
     */
    public function taxSummary(): ?array
    {
        $s = $this->tax_snapshot;
        if (! is_array($s)) {
            return null;
        }

        return [
            'label' => (string) ($s['label'] ?? 'Tax'),
            'prices_include_tax' => (bool) $this->prices_include_tax,
            'exempt' => (bool) ($s['exempt'] ?? false),
            'exemption_reference' => $s['exemption_reference'] ?? null,
            'shipping_tax_minor' => (int) ($s['shipping_tax_minor'] ?? 0),
            'breakdown' => array_map(fn (array $b) => ['name' => $b['name'], 'rate_bps' => $b['rate_bps'], 'base_minor' => $b['base_minor'], 'tax_minor' => $b['tax_minor']], $s['breakdown'] ?? []),
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderTimelineEvent, $this> */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(OrderTimelineEvent::class);
    }

    /**
     * Phase B8 addition — Module 13 §48: "One Order may contain ... Multiple Shipments."
     *
     * @return HasMany<\App\Domain\Shipping\Models\Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(\App\Domain\Shipping\Models\Shipment::class);
    }

    /**
     * Module 09 §45 (Phase B33): the returns made against this order.
     *
     * @return HasMany<\App\Domain\Returns\Models\ReturnRequest, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(\App\Domain\Returns\Models\ReturnRequest::class);
    }

    /** @return BelongsTo<self, $this> Module 09 §54: the order this one replaces */
    public function replacementFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_for_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Module 12 §13 (Phase B34): order total − store credit used = what the
     * payment is for.
     */
    public function payableMinor(): int
    {
        return max(0, (int) $this->grand_total_minor - (int) $this->store_credit_minor);
    }

    public function isGuestOrder(): bool
    {
        return $this->customer_id === null;
    }
}
