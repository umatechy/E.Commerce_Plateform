<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\RecipientType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationMessage> */
final class NotificationMessageFactory extends Factory
{
    protected $model = NotificationMessage::class;

    public function definition(): array
    {
        return [
            'message_type' => NotificationMessageType::Transactional,
            'channel' => NotificationChannel::Email,
            'recipient_type' => RecipientType::Customer,
            'destination' => fake()->safeEmail(),
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => NotificationStatus::Created,
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
