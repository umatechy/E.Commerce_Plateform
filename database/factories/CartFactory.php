<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cart> */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'guest_token' => bin2hex(random_bytes(32)),
            'status' => CartStatus::Active,
            'currency' => 'USD',
        ];
    }
}
