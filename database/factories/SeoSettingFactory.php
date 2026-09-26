<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Seo\Models\SeoableType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeoSetting> */
final class SeoSettingFactory extends Factory
{
    protected $model = SeoSetting::class;

    public function definition(): array
    {
        return ['seoable_type' => SeoableType::Product, 'seoable_id' => null, 'title' => fake()->sentence(3)];
    }
}
