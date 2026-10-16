<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Tenancy\Support\TenantContext;

/**
 * Phase B58 (Module 28 §57) — the reseller program's emails, sent by the
 * platform (no store): the invitation to the partner portal and the
 * application received. The invitation link carries a token, so it is a
 * secret variable: never stored readable.
 */
final class ResellerNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** The token travels in the #fragment, never to the server or its logs. */
    public function invitationUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/partner/join#token='.$token;
    }

    public function invitation(Reseller $reseller, string $url): void
    {
        app(TenantContext::class)->asPlatform(fn () => $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::Customer, null, $reseller->email,
            'Your invitation to the Umar Techy partner program',
            'Hi {{reseller.contact}}, {{reseller.business}} is invited to sell Umar Techy as a partner. Accept within 14 days with the account of this email address: {{reseller.invitation}} Your partner portal shows your clients\' stores, your commissions and your payouts.',
            ['reseller.contact' => $reseller->contact_name, 'reseller.business' => $reseller->business_name],
            'reseller:'.$reseller->id.':invitation:'.substr((string) $reseller->invitation_token_hash, 0, 16), 'resellers',
            secretVariables: ['reseller.invitation' => $url],
        ));
    }

    public function applicationReceived(Reseller $reseller): void
    {
        app(TenantContext::class)->asPlatform(fn () => $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::Customer, null, $reseller->email,
            'We received your partner application',
            'Hi {{reseller.contact}}, thank you for applying to sell Umar Techy as a partner. Our team reviews each application. If it is approved, we email you an invitation to the partner portal.',
            ['reseller.contact' => $reseller->contact_name], "reseller:{$reseller->id}:applied", 'resellers',
        ));
    }
}
