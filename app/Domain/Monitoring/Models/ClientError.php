<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * UI-25: one distinct script error seen in the admin, counted. Platform
 * level (not tenant scoped): only Super Admin reads it. Kept 30 days after
 * it was last seen (model:prune).
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $message
 * @property ?string $source
 * @property string $path
 * @property ?string $browser
 * @property int $occurrences
 * @property ?int $store_id
 * @property ?int $user_id
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 */
final class ClientError extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['fingerprint', 'message', 'source', 'path', 'browser', 'occurrences', 'store_id', 'user_id', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['occurrences' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** @return Builder<ClientError> */
    public function prunable(): Builder
    {
        return self::query()->where('last_seen_at', '<', now()->subDays(30));
    }
}
