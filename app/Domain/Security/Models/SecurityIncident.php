<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\Identity\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase B52 (Module 30 §59, §87–88; Module 32 §60–64): a security or operations incident. */
final class SecurityIncident extends Model
{
    use HasPublicId;

    public const SEVERITIES = [
        'sev0' => ['SEV0', 'Catastrophic: the platform is down or data of many stores is exposed or lost.'],
        'sev1' => ['SEV1', 'Major: a core service is down for many stores, or one store’s data is exposed.'],
        'sev2' => ['SEV2', 'Significant but limited: some stores or one feature affected; a workaround exists.'],
        'sev3' => ['SEV3', 'Minor: small impact, no data exposed.'],
        'sev4' => ['SEV4', 'Informational: worth recording, no impact.'],
    ];

    public const STATUSES = ['detected', 'triaged', 'investigating', 'containing', 'mitigating', 'resolved', 'closed'];

    public const SCOPES = ['platform', 'stores', 'store', 'provider'];

    protected $table = 'security_incidents';

    protected $fillable = ['reference', 'title', 'severity', 'status', 'component', 'scope', 'affected_store_ids', 'impact', 'owner_id', 'opened_by', 'detected_at', 'resolution', 'postmortem_reference'];

    protected function casts(): array
    {
        return ['affected_store_ids' => 'array', 'detected_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<SecurityIncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(SecurityIncidentUpdate::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
