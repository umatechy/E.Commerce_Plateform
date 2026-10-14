<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase B51 (Module 03 §37; Module 30 §140; Module 35 §42): an approved
 * permanent deletion of a store's data. Scheduled with a cooling period in
 * which it can be cancelled; run only after a verified backup; ends with a
 * check that nothing of the purged tables is left (`remaining`). The row
 * itself is kept as the deletion record.
 */
final class StoreDeletion extends Model
{
    public const SCHEDULED = 'scheduled';

    public const BACKING_UP = 'backing_up';

    public const PURGING = 'purging';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses that still lead to a deletion. */
    public const OPEN = [self::SCHEDULED, self::BACKING_UP, self::PURGING, self::FAILED];

    protected $table = 'store_deletions';

    protected $fillable = ['public_id', 'store_id', 'status', 'reason', 'requested_by', 'not_before', 'plan'];

    protected function casts(): array
    {
        return [
            'not_before' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'plan' => 'array', 'removed' => 'array', 'remaining' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
