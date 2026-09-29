<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Seo\Models\SeoSetting */
final class SeoSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seoable_type' => $this->seoable_type->value,
            'seoable_id' => $this->seoable_id,
            'title' => $this->title,
            'meta_description' => $this->meta_description,
            'canonical_override' => $this->canonical_override,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image_url' => $this->og_image_url,
            'robots_index' => $this->robots_index->value,
            'robots_follow' => $this->robots_follow->value,
        ];
    }
}
