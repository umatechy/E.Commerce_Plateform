<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationTemplate> */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'key' => 'order.created',
            'channel' => NotificationChannel::Email,
            'locale' => 'en',
            'subject' => 'Test Subject',
            'body' => 'Hello {{customer.name}}',
            'is_published' => false,
        ];
    }
}
