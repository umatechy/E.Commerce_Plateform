<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\NotificationMessage;

/**
 * Module 21 §15/§31 "Channel Architecture / Provider Abstraction."
 * NotificationService/DeliverNotificationJob depend ONLY on this
 * interface — never a concrete Mail call, SMS SDK, WhatsApp SDK, or
 * push SDK directly (this milestone's own explicit prohibition).
 * Adding a real provider later means one new class, zero changes
 * anywhere else.
 */
interface NotificationChannelContract
{
    public function providerName(): string;

    public function send(NotificationMessage $message): NotificationSendResult;
}
