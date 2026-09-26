<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentPage> */
final class ContentPageFactory extends Factory
{
    protected $model = ContentPage::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'slug' => fake()->unique()->slug(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => ContentPageStatus::Draft,
        ];
    }
}
