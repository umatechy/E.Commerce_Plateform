<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B38 — one translated field of a product, category or brand in one
 * language. Written only by TranslationService.
 *
 * @property int $id
 * @property string $translatable_type
 * @property int $translatable_id
 * @property string $locale
 * @property string $field
 * @property string $value
 */
final class ContentTranslation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}
