<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Exceptions\CustomerActionRefusedException;
use App\Domain\Customers\Models\CustomerMerge;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Module 10 §56 "Customer Merge": joins a duplicate record (the source)
 * into the customer who stays (the target), as the blueprint orders it:
 * validate ownership → orders → addresses → tags/groups → wishlist →
 * archive the source.
 *
 * - Same store only. Both rows come from the tenant-scoped binding, and
 *   every write below names the store again.
 * - Explicit: staff choose both records, give a reason and type the
 *   target's email; the route asks for the password again (step-up).
 *   Nothing is ever merged automatically (§57).
 * - Concurrency: both rows are locked (lower id first) and re-checked
 *   inside the transaction, so two merges of the same record cannot both
 *   run; a record is merged once (unique source in `customer_merges`).
 * - The target keeps its own identity: name, email, phone, password,
 *   email confirmation and marketing consent. Consent is never widened
 *   by a merge.
 * - The source is not deleted: it is archived, marked as merged into the
 *   target, and loses its sessions — its history stays traceable.
 *
 * Refused: the same record twice; an erased record; a record already
 *   merged; a blocked one (unblock first, so a block is never lost); a
 *   target that is not active; and a source with an account of its own,
 *   because its sign-in would be lost (merge the other way round, into
 *   the record with the account; two accounts are the customer's choice
 *   and are not merged by staff).
 */
final class CustomerMerger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, int> what moved, by kind */
    public function merge(Customer $source, Customer $target, string $reason, User $actor): array
    {
        if ($source->is($target)) {
            throw new CustomerActionRefusedException('Choose two different customers.', 'same_customer', 422);
        }

        $moved = DB::transaction(function () use ($source, $target, $reason, $actor) {
            // Lower id first, so two opposite merges cannot deadlock.
            $ids = [$source->id, $target->id];
            sort($ids);
            $locked = Customer::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($source->id);
            $into = $locked->get($target->id);
            if ($from === null || $into === null) {
                throw new CustomerActionRefusedException('One of these customers no longer exists.', 'not_found', 404);
            }
            $this->assertMergeable($from, $into);

            $store = $from->store_id;
            $moved = [];
            $move = function (string $table, string $column, array $where = []) use ($store, $from, $into): int {
                return DB::table($table)->where('store_id', $store)->where($column, $from->id)->where($where)->update([$column => $into->id]);
            };

            // Orders, and what hangs on the customer through them.
            $moved['orders'] = $move('orders', 'customer_id');
            $moved['payments'] = $move('payments', 'customer_id');
            $moved['returns'] = $move('return_requests', 'customer_id'); // Phase B33: they follow their orders
            // Coupon "per customer" limits keep counting the same person.
            $moved['promotion_usages'] = $move('promotion_usages', 'customer_id');

            // Addresses: the target's default stays the default.
            $targetHasDefault = DB::table('customer_addresses')->where('store_id', $store)->where('customer_id', $into->id)->where('is_default', true)->exists();
            if ($targetHasDefault) {
                DB::table('customer_addresses')->where('store_id', $store)->where('customer_id', $from->id)->update(['is_default' => false]);
            }
            $moved['addresses'] = $move('customer_addresses', 'customer_id');

            // Tags: the union. Group: the target's, or the source's if it has none.
            $targetTags = DB::table('customer_tag_assignments')->where('customer_id', $into->id)->pluck('customer_tag_id')->all();
            $moved['tags'] = DB::table('customer_tag_assignments')->where('store_id', $store)->where('customer_id', $from->id)->whereNotIn('customer_tag_id', $targetTags ?: [0])->update(['customer_id' => $into->id]);
            DB::table('customer_tag_assignments')->where('store_id', $store)->where('customer_id', $from->id)->delete();
            if ($into->customer_group_id === null && $from->customer_group_id !== null) {
                $into->forceFill(['customer_group_id' => $from->customer_group_id])->save();
                $moved['group'] = 1;
            }

            // Wishlist: items the target already has stay with the source (unique per customer).
            $key = fn (object $item): string => $item->product_id.':'.($item->product_variant_id ?? '');
            $targetItems = DB::table('wishlist_items')->where('store_id', $store)->where('customer_id', $into->id)->get(['product_id', 'product_variant_id'])->map($key)->flip();
            $movable = DB::table('wishlist_items')->where('store_id', $store)->where('customer_id', $from->id)->get(['id', 'product_id', 'product_variant_id'])
                ->reject(fn (object $item) => $targetItems->has($key($item)))->pluck('id')->all();
            $moved['wishlist_items'] = DB::table('wishlist_items')->whereIn('id', $movable ?: [0])->update(['customer_id' => $into->id]);

            // Notes, messages sent, campaign history, support requests.
            $moved['notes'] = $move('customer_notes', 'customer_id');
            $moved['notifications'] = $move('notification_messages', 'recipient_id', ['recipient_type' => 'customer']);
            $moved['campaign_recipients'] = DB::table('campaign_recipients')->where('store_id', $store)->where('customer_id', $from->id)
                ->whereNotIn('campaign_id', DB::table('campaign_recipients')->where('customer_id', $into->id)->pluck('campaign_id')->all() ?: [0])
                ->update(['customer_id' => $into->id]);
            $tickets = DB::table('support_tickets')->where('store_id', $store)->where('requester_type', 'customer')->where('requester_id', $from->id)->pluck('id')->all();
            $moved['support_tickets'] = DB::table('support_tickets')->whereIn('id', $tickets ?: [0])->update(['requester_id' => $into->id]);
            DB::table('support_messages')->whereIn('ticket_id', $tickets ?: [0])->where('author_type', 'requester')->where('author_id', $from->id)->update(['author_id' => $into->id]);

            // The source keeps nothing it could be used with.
            DB::table('customer_email_verifications')->where('customer_id', $from->id)->delete();
            DB::table('customer_password_resets')->where('customer_id', $from->id)->delete();
            $from->tokens()->delete();

            $from->forceFill([
                'status' => CustomerStatus::Archived,
                'status_reason' => 'Merged into another customer record.',
                'status_changed_at' => now(),
                'merged_into_customer_id' => $into->id,
                'merged_at' => now(),
                'customer_group_id' => null,
            ])->save();

            $moved = array_filter($moved);
            CustomerMerge::query()->create([
                'store_id' => $store, 'source_customer_id' => $from->id, 'target_customer_id' => $into->id,
                'actor_user_id' => $actor->id, 'reason' => $reason, 'moved' => $moved,
            ]);

            return $moved;
        });

        $this->audit->record('customer.merged_into', ['into' => $target->public_id, 'reason' => $reason, 'moved' => $moved], $source, actor: $actor);
        $this->audit->record('customer.merged_from', ['from' => $source->public_id, 'reason' => $reason, 'moved' => $moved], $target, actor: $actor);

        return $moved;
    }

    private function assertMergeable(Customer $from, Customer $into): void
    {
        if ($from->store_id !== $into->store_id) {
            // Unreachable through the API (tenant-scoped binding); kept as the last line.
            throw new CustomerActionRefusedException('Customers of different stores are never merged.', 'other_store', 404);
        }
        if ($from->erased_at !== null || $into->erased_at !== null) {
            throw new CustomerActionRefusedException('A customer whose personal data was erased cannot be merged.', 'erased');
        }
        if ($from->merged_into_customer_id !== null) {
            throw new CustomerActionRefusedException('This customer was already merged into another record.', 'already_merged');
        }
        if ($into->merged_into_customer_id !== null) {
            throw new CustomerActionRefusedException('The customer you chose was itself merged into another record. Choose that one.', 'target_merged');
        }
        if ($from->standing() === CustomerStatus::Blocked || $into->standing() === CustomerStatus::Blocked) {
            throw new CustomerActionRefusedException('A blocked customer cannot be merged. Unblock them first, then block the merged customer if needed.', 'blocked');
        }
        if ($into->standing() !== CustomerStatus::Active) {
            throw new CustomerActionRefusedException('The customer who stays must be active. Restore them first.', 'target_inactive');
        }
        if ($from->isRegistered()) {
            throw new CustomerActionRefusedException($into->isRegistered()
                ? 'Both customers have their own account. Accounts are not merged by staff: the customer can stop using one of them.'
                : 'This customer has an account and would lose their sign-in. Merge the other way round: open the other customer and merge it into this one.', $into->isRegistered() ? 'both_registered' : 'source_registered');
        }
    }
}
