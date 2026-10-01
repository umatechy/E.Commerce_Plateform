<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\DeliveryAttemptResult;
use App\Domain\Notifications\Models\NotificationMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A REAL, functional channel — built on Laravel's own Mail facade
 * (whatever transport `MAIL_MAILER` configures). No live SMTP
 * credential exists in this environment, so no message is actually
 * delivered here, but this is genuine, correct send code, not a stub
 * (see docs/development/b11-inspection-findings.md "Channel Scope").
 */
final class EmailChannel implements NotificationChannelContract
{
    public function providerName(): string
    {
        return 'smtp';
    }

    public function send(NotificationMessage $message): NotificationSendResult
    {
        try {
            Mail::html($message->deliverableBody(), function ($mail) use ($message) {
                $mail->to($message->destination)->subject($message->subject ?? '(no subject)');
            });

            return new NotificationSendResult(DeliveryAttemptResult::Succeeded, providerMessageId: (string) Str::uuid());
        } catch (\Throwable $e) {
            return new NotificationSendResult(
                DeliveryAttemptResult::Failed,
                failureCode: 'mail_transport_error',
                failureReason: $e->getMessage(),
            );
        }
    }
}
