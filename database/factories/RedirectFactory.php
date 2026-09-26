<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Seo\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Redirect> */
final class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    public function definition(): array
    {
        return [
            'source_path' => '/old-'.fake()->unique()->slug(),
            'destination_path' => '/new-'.fake()->slug(),
            'status_code' => 301,
            'is_active' => true,
        ];
    }
}
