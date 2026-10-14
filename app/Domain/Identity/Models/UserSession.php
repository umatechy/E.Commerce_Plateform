<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B52 (SRS AUTH-005; Module 32 §9; Module 30 §4–5): one signed-in
 * staff session. Only the SHA-256 of the session id is stored, so a row
 * can never be used to take over the session.
 */
final class UserSession extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'user_sessions';

    protected $fillable = ['user_id', 'session_hash', 'privileged', 'with_mfa', 'ip_address', 'user_agent', 'last_seen_at'];

    protected $hidden = ['session_hash'];

    protected function casts(): array
    {
        return ['privileged' => 'boolean', 'with_mfa' => 'boolean', 'created_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
