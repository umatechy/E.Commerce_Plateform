<?php

declare(strict_types=1);

namespace App\Domain\Customers\Http\Resources;

use App\Domain\Orders\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A customer as store staff see it (Module 10 §55). Staff-only: never
 * returned by a customer-facing API. Has no password, token or note
 * text; notes have their own endpoint.
 *
 * `orders_count`, `total_spent_minor` and `last_order_at` are present
 * when the row came from CustomerDirectory (the list and the detail).
 *
 * @mixin Customer
 */
final class StaffCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastOrder = $this->resource->getAttribute('last_order_at');

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->standing()->value,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'source' => $this->source?->value,
            'registered' => $this->isRegistered(),
            'email_verified' => $this->email_verified_at !== null,
            'marketing_email_opt_in' => (bool) $this->marketing_email_opt_in,
            'erased' => $this->erased_at !== null,
            'group' => $this->whenLoaded('group', fn () => $this->group === null ? null : ['id' => $this->group->public_id, 'name' => $this->group->name]),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => ['id' => $tag->public_id, 'name' => $tag->name])->values()),
            'orders_count' => $this->when($this->resource->getAttribute('orders_count') !== null, fn () => (int) $this->resource->getAttribute('orders_count')),
            'total_spent_minor' => $this->when($this->resource->getAttribute('total_spent_minor') !== null, fn () => (int) $this->resource->getAttribute('total_spent_minor')),
            'last_order_at' => $lastOrder ? Carbon::parse($lastOrder, 'UTC')->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
