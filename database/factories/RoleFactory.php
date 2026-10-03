<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            // Prefixed: a job title such as "Manager" would otherwise take the
            // slug of a system role every store is seeded with (flaky
            // uniq_roles_store_id_slug violation, seen in CI on B32).
            'slug' => 'custom-'.\Illuminate\Support\Str::slug($name),
            'is_system' => false,
        ];
    }
}
