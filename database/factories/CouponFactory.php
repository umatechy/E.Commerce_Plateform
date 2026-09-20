<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Promotions\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('CODE####'));

        return ['code' => $code, 'code_normalized' => $code, 'is_active' => true];
    }
}
