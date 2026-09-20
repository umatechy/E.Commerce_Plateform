<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only in practice (status is set once at creation) — never updated after the fact in B10's boundary-only scope. */
final class CampaignRecipient extends Model
{
    use BelongsToTenant;

    protected $table = 'campaign_recipients';

    protected $fillable = ['store_id', 'campaign_id', 'customer_id', 'status', 'queued_at'];

    protected function casts(): array
    {
        return ['status' => CampaignRecipientStatus::class, 'queued_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
