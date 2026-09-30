<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Packages\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
final class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'code' => 'pkg-'.fake()->unique()->bothify('????####'), // packages.code is VARCHAR(32); slug(2) occasionally overflowed it
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
