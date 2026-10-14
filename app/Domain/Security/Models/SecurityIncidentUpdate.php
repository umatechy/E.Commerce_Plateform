<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase B52: one line of an incident's timeline. Added, never edited (Module 30 §59: preserve timeline and actions). */
final class SecurityIncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'security_incident_updates';

    protected $fillable = ['security_incident_id', 'kind', 'from_value', 'to_value', 'note', 'author_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
