<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B57 (Module 28 §3.2, §7): a store's affiliate program. Its rules
 * live in immutable versions (AffiliateProgramVersion); the program points
 * at the one in force.
 *
 * @property-read AffiliateProgramVersion|null $currentVersion none until the owner first saves rules
 */
final class AffiliateProgram extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'affiliate_programs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepts_applications' => 'boolean'];
    }

    /** @return BelongsTo<AffiliateProgramVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgramVersion::class, 'current_version_id');
    }

    /** @return HasMany<AffiliateProgramVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AffiliateProgramVersion::class)->orderByDesc('version');
    }
}
