<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $key = fake()->unique()->word().'.'.fake()->randomElement(['view', 'create', 'update', 'delete']);

        return [
            'key' => $key,
            'group' => explode('.', $key)[0],
            'description' => fake()->sentence(),
        ];
    }
}
