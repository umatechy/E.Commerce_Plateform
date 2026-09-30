<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class SeoSetting extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'seo_settings';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'robots_index' => 'index',
        'robots_follow' => 'follow',
    ];

    protected $fillable = [
        'store_id', 'seoable_type', 'seoable_id', 'title', 'meta_description',
        'canonical_override', 'og_title', 'og_description', 'og_image_url',
        'robots_index', 'robots_follow',
    ];

    protected function casts(): array
    {
        return [
            'seoable_type' => SeoableType::class,
            'robots_index' => RobotsDirective::class,
            'robots_follow' => RobotsDirective::class,
        ];
    }
}
