<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase P4b: one automatic message of a store (welcome, post-purchase,
 * win-back, birthday) — on or off, when, and what it says.
 *
 * @property int $id
 * @property int $store_id
 * @property string $kind
 * @property bool $is_enabled
 * @property int $delay_days
 * @property string $subject
 * @property string $body
 * @property ?int $coupon_id
 * @property ?\Illuminate\Support\Carbon $enabled_at
 */
final class MarketingAutomation extends Model
{
    use BelongsToTenant;

    public const KINDS = ['welcome', 'post_purchase', 'win_back', 'birthday'];

    protected $table = 'marketing_automations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'delay_days' => 'integer', 'enabled_at' => 'datetime'];
    }
}
