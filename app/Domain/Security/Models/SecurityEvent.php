<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B52 (Module 32 §58): one thing security monitoring saw. Platform
 * level (read only by platform staff), kept for security.events_retention_days.
 */
final class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'security_events';

    protected $fillable = ['type', 'severity', 'user_id', 'subject', 'store_id', 'ip_address', 'context', 'request_id', 'alerted'];

    protected function casts(): array
    {
        return ['context' => 'array', 'alerted' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
