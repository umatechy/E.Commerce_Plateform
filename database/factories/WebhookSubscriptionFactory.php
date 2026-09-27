<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Models\WebhookSubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookSubscription> */
final class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        // No implicit parent-store inference (Laravel's ->for($store)
        // only sets store_id, not the separate developer_application_id
        // foreign key) — callers needing a specific, store-consistent
        // application must pass 'developer_application_id' explicitly.
        return [
            'developer_application_id' => fn () => DeveloperApplication::factory()->create()->id,
            'url' => 'https://93.184.216.34/webhook',
            'signing_secret' => Str::random(48),
            'subscribed_events' => ['order.created'],
            'status' => WebhookSubscriptionStatus::Active,
        ];
    }
}
