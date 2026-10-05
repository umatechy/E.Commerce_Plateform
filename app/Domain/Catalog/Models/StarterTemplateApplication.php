<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B45: one starter template applied to a store — the history behind
 * "applied on …" (the copied structure lives in the store's own catalogue).
 *
 * @property int $id
 * @property string $template_key
 * @property int $template_version
 * @property ?int $applied_by_user_id
 * @property array<string, mixed> $summary
 * @property \Illuminate\Support\Carbon $created_at
 */
final class StarterTemplateApplication extends Model
{
    use BelongsToTenant;

    protected $fillable = ['store_id', 'template_key', 'template_version', 'applied_by_user_id', 'summary'];

    protected function casts(): array
    {
        return ['template_version' => 'integer', 'summary' => 'array'];
    }
}
