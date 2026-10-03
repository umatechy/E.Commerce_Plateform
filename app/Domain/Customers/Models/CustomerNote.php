<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 10 §32: an internal note about a customer. Staff only: no
 * customer-facing API or export of the customer's own data includes it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $customer_id
 * @property ?int $author_user_id
 * @property string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CustomerNote extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'customer_notes';

    protected $fillable = ['store_id', 'customer_id', 'author_user_id', 'body'];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
