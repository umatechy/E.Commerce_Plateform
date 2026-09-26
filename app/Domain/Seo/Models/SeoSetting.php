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
