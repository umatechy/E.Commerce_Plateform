<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperApplication> */
final class DeveloperApplicationFactory extends Factory
{
    protected $model = DeveloperApplication::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => $this->faker->company().' Integration',
            'status' => ApplicationStatus::Active,
        ];
    }
}
