<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'method' => PaymentMethod::CashOnDelivery,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
