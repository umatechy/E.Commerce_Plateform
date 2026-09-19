<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'package_id' => Package::factory(),
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(),
        ];
    }

    public function trialing(): self
    {
        return $this->state([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => SubscriptionStatus::Suspended]);
    }

    public function expired(): self
    {
        return $this->state(['status' => SubscriptionStatus::Expired]);
    }
}
