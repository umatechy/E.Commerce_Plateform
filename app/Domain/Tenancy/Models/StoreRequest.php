<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase S1: a business asking Umar Techy for a store (the platform is
 * closed to public sign-up). A platform record, not a store's: it exists
 * before any store does.
 *
 * @property int $id
 * @property string $public_id
 * @property string $business_name
 * @property string $contact_name
 * @property string $email
 * @property string $phone
 * @property ?string $city
 * @property ?string $business_category
 * @property ?string $package_code
 * @property ?string $domain
 * @property string $hosting umar_techy | own_server | not_sure
 * @property ?string $message
 * @property string $status new | contacted | store_created | declined
 * @property ?string $staff_note
 * @property ?int $store_id
 * @property ?int $handled_by
 */
final class StoreRequest extends Model
{
    use HasPublicId;

    public const HOSTING = ['umar_techy', 'own_server', 'not_sure'];

    public const STATUSES = ['new', 'contacted', 'store_created', 'declined'];

    protected $table = 'store_requests';

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
