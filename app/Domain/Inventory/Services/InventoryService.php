<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Exceptions\DuplicateOpeningStockException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockMovementType;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code path in the platform that mutates inventories.on_hand /
 * .reserved. Module 08 Final Architectural Rule #3: "Inventory is
 * authoritative on the server" — this class IS that authority.
 *
 * ============================================================
 * CONCURRENCY STRATEGY (Module 08 §23, this milestone's dedicated
 * "Concurrency" section — documented per its explicit requirement)
 * ============================================================
 * Every balance mutation is a SINGLE atomic SQL UPDATE statement with
 * the safety condition baked into its WHERE clause, e.g.:
 *
 *   UPDATE inventories SET on_hand = on_hand - :qty
 *   WHERE id = :id AND on_hand >= :qty
 *
 * ...and the PHP code checks the statement's AFFECTED ROW COUNT (not a
 * prior SELECT) to know whether it succeeded. This closes the exact
 * race this milestone describes (stock=1, two concurrent buyers both
 * read 1, both proceed): there is no "read stock, then decide, then
 * write" round-trip in PHP for the safety-critical decision — the
 * database itself evaluates the condition and applies the change
 * atomically, and only one of two concurrent requests can ever see
 * affected-rows=1 for a WHERE clause that only one of them can satisfy.
 *
 * This was chosen over SELECT ... FOR UPDATE row locking because it
 * requires no explicit lock/unlock lifecycle, cannot deadlock against
 * another inventory row, and scales better under high concurrency
 * (no lock contention queue) — the same philosophy already established
 * in Phase B2's UsageTrackingService (atomic INSERT ... ON DUPLICATE
 * KEY UPDATE) rather than a new pattern invented for this module.
 *
 * TRANSACTION BOUNDARY: the balance UPDATE and its corresponding
 * StockMovement ledger row are written in the SAME DB::transaction() —
 * never one without the other (this milestone's "Transaction
 * Boundaries" requirement).
 *
 * IDEMPOTENCY: every mutating method accepts an idempotency key,
 * enforced by a unique DB constraint on (store_id, idempotency_key). A
 * retried request with the same key returns the ALREADY-CREATED
 * movement/reservation rather than applying the change twice.
 */
final class InventoryService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * Module 08 §32 "Opening Stock". Allowed exactly once per inventory
     * record (on_hand must currently be 0 with no prior movement at
     * all) — "must not silently overwrite existing inventory" (this
     * milestone's explicit rule). Subsequent stock-in goes through
     * adjustStock().
     *
     * @throws DuplicateOpeningStockException
     */
    public function setOpeningStock(
        Inventory $inventory,
        int $quantity,
        string $reason,
        int $actorId,
        string $idempotencyKey,
    ): StockMovement {
        if ($existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        return DB::transaction(function () use ($inventory, $quantity, $reason, $actorId, $idempotencyKey) {
            $hasAnyMovement = StockMovement::query()->where('inventory_id', $inventory->id)->exists();

            if ($hasAnyMovement || $inventory->on_hand !== 0) {
                throw new DuplicateOpeningStockException();
            }

            $affected = DB::table('inventories')
                ->where('id', $inventory->id)
                ->where('on_hand', 0)
                ->update(['on_hand' => $quantity, 'updated_at' => now()]);

            if ($affected === 0) {
                // Lost the race to a concurrent opening-stock/adjustment call.
                throw new DuplicateOpeningStockException();
            }

            return $this->recordMovement(
                $inventory, StockMovementType::OpeningBalance, $quantity,
                previousOnHand: 0, newOnHand: $quantity,
                reason: $reason, actorId: $actorId, idempotencyKey: $idempotencyKey,
            );
        });
    }

    /**
     * Module 08 §33 "Stock Adjustment". $delta may be positive
     * (ADJUSTMENT_IN — found stock, correction upward) or negative
     * (ADJUSTMENT_OUT — damaged/lost/correction downward). Adjustments
     * ALWAYS require the resulting on_hand to stay >= 0, regardless of
     * the store's allow_overselling setting — overselling is a SALES
     * policy (Module 08 §24), not a physical-count/data-correction
     * policy; a documented distinction, not an oversight.
     *
     * @throws InsufficientStockException
     */
    public function adjustStock(
        Inventory $inventory,
        int $delta,
        string $reason,
        int $actorId,
        string $idempotencyKey,
    ): StockMovement {
        if ($existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        if ($delta === 0) {
            throw new \InvalidArgumentException('Adjustment quantity cannot be zero.');
        }

        return DB::transaction(function () use ($inventory, $delta, $reason, $actorId, $idempotencyKey) {
            $type = $delta > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;
            $previousOnHand = $inventory->on_hand;

            $query = DB::table('inventories')->where('id', $inventory->id);

            if ($delta < 0) {
                // Atomic guard: only apply if on_hand would not go negative.
                $query->where('on_hand', '>=', abs($delta));
            }

            $affected = $query->update([
                'on_hand' => DB::raw("on_hand + ({$delta})"),
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                throw new InsufficientStockException($inventory->id, abs($delta));
            }

            $newOnHand = $previousOnHand + $delta;

            $movement = $this->recordMovement(
                $inventory, $type, $delta,
                previousOnHand: $previousOnHand, newOnHand: $newOnHand,
                reason: $reason, actorId: $actorId, idempotencyKey: $idempotencyKey,
            );

            $this->maybeRecordLowStockEvent($inventory->fresh());

            return $movement;
        });
    }

    /**
     * Module 08 §20-22 "Stock Reservation". Atomic guard mirrors
     * adjustStock()'s strategy: a single UPDATE conditioned on
     * (on_hand - reserved) >= quantity, unless the store explicitly
     * allows overselling (in which case the reservation is granted
     * unconditionally — Module 08 §24's "if enabled... clearly
     * represent negative or backordered stock" extends to reservations
     * too, since a reservation is the mechanism a future Orders module
     * will use to sell).
     *
     * @throws InsufficientStockException
     */
    public function reserve(
        Inventory $inventory,
        int $quantity,
        string $idempotencyKey,
        int $ttlMinutes = 15,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockReservation {
        if ($existing = $this->findReservationByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be positive.');
        }

        $allowOverselling = Store::query()->find($this->context->storeId())?->allow_overselling ?? false;

        return DB::transaction(function () use ($inventory, $quantity, $idempotencyKey, $ttlMinutes, $referenceType, $referenceId, $allowOverselling) {
            $query = DB::table('inventories')->where('id', $inventory->id);

            if (! $allowOverselling) {
                $query->whereRaw('(on_hand - reserved) >= ?', [$quantity]);
            }

            $affected = $query->update([
                'reserved' => DB::raw("reserved + {$quantity}"),
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                throw new InsufficientStockException($inventory->id, $quantity);
            }

            $reservation = StockReservation::query()->create([
                'store_id' => $this->context->storeId(),
                'inventory_id' => $inventory->id,
                'quantity' => $quantity,
                'status' => ReservationStatus::Active,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $this->recordMovement(
                $inventory, StockMovementType::Reservation, -$quantity,
                previousOnHand: $inventory->on_hand, newOnHand: $inventory->on_hand, // reservation never changes on_hand
                reason: 'Stock reserved', actorId: null,
                idempotencyKey: $idempotencyKey.':movement',
                referenceType: 'reservation', referenceId: $reservation->id,
            );

            return $reservation;
        });
    }

    /**
     * Module 08 §21: reservation → RELEASED (manual/failed checkout) or
     * → EXPIRED (via expireStale(), below) or → CONVERTED (future
     * Orders module, not implemented in B4). Releasing an
     * already-released/expired/converted/cancelled reservation is a
     * safe no-op (idempotent by status check, not just by idempotency
     * key — a release call naturally has no new key of its own).
     */
    public function release(StockReservation $reservation, ReservationStatus $terminalStatus = ReservationStatus::Released): void
    {
        if ($reservation->status !== ReservationStatus::Active && $reservation->status !== ReservationStatus::Pending) {
            return; // already terminal — safe no-op
        }

        DB::transaction(function () use ($reservation, $terminalStatus) {
            DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->update([
                    'reserved' => DB::raw("reserved - {$reservation->quantity}"),
                    'updated_at' => now(),
                ]);

            $reservation->update(['status' => $terminalStatus, 'released_at' => now()]);

            $inventory = Inventory::query()->withoutTenantScope()->find($reservation->inventory_id);

            $this->recordMovement(
                $inventory, StockMovementType::ReservationRelease, $reservation->quantity,
                previousOnHand: $inventory->on_hand, newOnHand: $inventory->on_hand,
                reason: "Reservation {$terminalStatus->value}", actorId: null,
                idempotencyKey: Str::uuid()->toString().':release', // release itself need not be idempotent-keyed by caller — status check above IS the idempotency guard
                referenceType: 'reservation', referenceId: $reservation->id,
            );
        });
    }

    /**
     * Phase B8 addition — the exact extension point this class's own
     * Phase B4 docblock deferred ("actual commit/deduction is deferred
     * to whichever future module introduces a real commit step").
     * Called by App\Domain\Shipping\Services\ShipmentService when a
     * ShipmentItem ships some or all of a reservation's quantity.
     *
     * Supports PARTIAL fulfillment (a reservation for 5 units may be
     * fulfilled 3 now, 2 later across a second shipment) — the
     * reservation's own `quantity` is reduced by the fulfilled amount;
     * when it reaches 0, the reservation is marked Converted (a
     * ReservationStatus case that has existed since Phase B4 but was
     * never reached until this method existed).
     *
     * CONCURRENCY: same atomic-conditional-UPDATE strategy as every
     * other balance mutation in this class — on_hand AND reserved are
     * decremented in ONE statement, guarded by both having enough,
     * with the decision made by the database via affected-row count,
     * never a prior SELECT.
     *
     * @throws InsufficientStockException
     */
    public function fulfillReservation(StockReservation $reservation, int $quantity, string $idempotencyKey): StockMovement
    {
        if ($existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        if ($quantity <= 0 || $quantity > $reservation->quantity) {
            throw new \InvalidArgumentException('Fulfillment quantity must be positive and cannot exceed the reservation\'s remaining quantity.');
        }

        return DB::transaction(function () use ($reservation, $quantity, $idempotencyKey) {
            $inventory = Inventory::query()->withoutTenantScope()->find($reservation->inventory_id);
            $previousOnHand = $inventory->on_hand;

            $affected = DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->where('on_hand', '>=', $quantity)
                ->where('reserved', '>=', $quantity)
                ->update([
                    'on_hand' => DB::raw("on_hand - {$quantity}"),
                    'reserved' => DB::raw("reserved - {$quantity}"),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new InsufficientStockException($inventory->id, $quantity);
            }

            $movement = $this->recordMovement(
                $inventory, StockMovementType::SaleOut, -$quantity,
                previousOnHand: $previousOnHand, newOnHand: $previousOnHand - $quantity,
                reason: 'Shipment fulfillment', actorId: null, idempotencyKey: $idempotencyKey,
                referenceType: 'reservation', referenceId: $reservation->id,
            );

            $remaining = $reservation->quantity - $quantity;

            if ($remaining <= 0) {
                $reservation->update(['status' => ReservationStatus::Converted, 'released_at' => now()]);
            } else {
                $reservation->update(['quantity' => $remaining]);
            }

            return $movement;
        });
    }

    /**
     * Module 08 §22 "Reservation Expiry". Called by the scheduled
     * console command (App\Domain\Inventory\Console\ExpireStaleReservations
     * — same dispatcher-pattern precedent as ADR-004's outbox:publish).
     */
    public function expireStaleReservations(int $limit = 200): int
    {
        $stale = StockReservation::query()->withoutTenantScope()
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get();

        foreach ($stale as $reservation) {
            $this->context->resolveToStore($reservation->store_id);
            $this->release($reservation, ReservationStatus::Expired);
        }

        return $stale->count();
    }

    private function recordMovement(
        Inventory $inventory,
        StockMovementType $type,
        int $quantity,
        int $previousOnHand,
        int $newOnHand,
        ?string $reason,
        ?int $actorId,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return StockMovement::query()->create([
            'store_id' => $this->context->storeId(),
            'inventory_id' => $inventory->id,
            'type' => $type,
            'quantity' => $quantity,
            'previous_on_hand' => $previousOnHand,
            'new_on_hand' => $newOnHand,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actorId,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function findByIdempotencyKey(string $key): ?StockMovement
    {
        return StockMovement::query()->where('idempotency_key', $key)->first();
    }

    private function findReservationByIdempotencyKey(string $key): ?StockReservation
    {
        return StockReservation::query()->where('idempotency_key', $key)->first();
    }

    /**
     * Module 08 §27-28 "Low Stock / Stock Alerts", Final Rule #21
     * (reliable event delivery). Fires ONLY on the transition into
     * low-stock (not on every adjustment while already low) to avoid
     * duplicate alerts — checked by comparing against the movement's
     * own previous/new on_hand rather than a separate query.
     */
    private function maybeRecordLowStockEvent(Inventory $inventory): void
    {
        if (! $inventory->isLowStock()) {
            return;
        }

        // At most one alert per hour per inventory record: the hour is part
        // of the unique idempotency key, so a second low-stock adjustment
        // within the hour used to hit the unique index and roll back the
        // whole stock adjustment. The caller's UPDATE of this inventory row
        // holds its row lock until commit, so this check cannot race with
        // another adjustment of the same record.
        $idempotencyKey = "inventory:{$inventory->id}:low_stock:".now()->format('Y-m-d-H');

        if (OutboxEvent::query()->withoutTenantScope()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $this->outbox->recordEvent(
            eventType: 'inventory.low_stock_detected',
            payload: [
                'inventory_id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'product_variant_id' => $inventory->product_variant_id,
                'available' => $inventory->available(),
                'reorder_point' => $inventory->reorder_point,
            ],
            idempotencyKey: $idempotencyKey,
        );
    }
}
