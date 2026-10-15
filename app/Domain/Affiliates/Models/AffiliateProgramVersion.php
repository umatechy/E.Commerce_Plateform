<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B57 (Module 28 §2.4, §47, §108): one immutable set of program
 * rules and terms. A change makes a new version; commissions keep the
 * version they were calculated with.
 */
final class AffiliateProgramVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'affiliate_program_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'version' => 'integer'];
    }
}
