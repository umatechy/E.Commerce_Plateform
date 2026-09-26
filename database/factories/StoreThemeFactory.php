<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreTheme> */
final class StoreThemeFactory extends Factory
{
    protected $model = StoreTheme::class;

    public function definition(): array
    {
        $config = ['tokens' => ['primary' => '#000000'], 'branding' => [], 'sections' => []];

        return [
            'theme_id' => fn () => Theme::query()->firstOrCreate(['key' => 'default'], ['name' => 'Default Storefront Theme', 'version' => '1.0.0', 'status' => 'active'])->id,
            'draft_config' => $config,
            'published_config' => $config,
            'published_at' => now(),
        ];
    }
}
