<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attribute> */
final class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'key' => \Illuminate\Support\Str::slug($name, '_'),
            'type' => 'select',
        ];
    }
}
