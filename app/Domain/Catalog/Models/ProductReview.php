<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner decision 15 — Module 05 §27: a customer's rating and review of a
 * product they bought. Shown on the storefront only once the store approved
 * it (Module 05 §27 "store administrators must control moderation").
 *
 * @property int $id
 * @property int $product_id
 * @property ?int $customer_id
 * @property ?int $order_id
 * @property int $rating 1–5
 * @property ?string $title
 * @property string $body
 * @property string $author_name
 * @property string $status pending | approved | rejected
 * @property bool $verified_purchase
 * @property ?string $reply
 * @property ?\Illuminate\Support\Carbon $replied_at
 * @property ?\Illuminate\Support\Carbon $moderated_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProductReview extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    protected $fillable = ['product_id', 'customer_id', 'order_id', 'rating', 'title', 'body', 'author_name', 'status', 'verified_purchase'];

    protected $attributes = ['status' => self::PENDING, 'verified_purchase' => false];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'verified_purchase' => 'boolean', 'replied_at' => 'datetime', 'moderated_at' => 'datetime'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
