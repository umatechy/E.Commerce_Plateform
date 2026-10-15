<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Billing\Services\BillingContact;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;

/**
 * Phase B57 (Module 28 §57) — the affiliate program's transactional emails,
 * through the notification pipeline (Module 21): welcome with the private
 * portal link, application received, and the store told of a new
 * application. The portal link carries a token, so it is a secret
 * variable: never stored readable.
 */
final class AffiliateNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SeoResolver $seo,
        private readonly BillingContact $contacts,
    ) {}

    /** The portal address: the token travels in the #fragment, never to the server or its logs. */
    public function portalUrl(Affiliate $affiliate, string $token): string
    {
        return $this->storefront($affiliate).'/affiliate#token='.$token;
    }

    public function welcome(Affiliate $affiliate, string $token): void
    {
        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::Customer, null, $affiliate->email,
            'You are an affiliate of {{store.name}}',
            'Hi {{affiliate.name}}, you are now an affiliate of {{store.name}}. Your code is {{affiliate.code}}. Your link, your results and your payouts are on your private page: {{affiliate.portal}} Keep this link to yourself: anyone with it can see your page.',
            [...$this->common($affiliate)], 'affiliate:'.$affiliate->id.':welcome:'.substr(hash('sha256', $token), 0, 16), 'affiliates',
            ['affiliate.portal' => $this->portalUrl($affiliate, $token)],
        );
    }

    public function applicationReceived(Affiliate $affiliate): void
    {
        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::Customer, null, $affiliate->email,
            'We received your application to {{store.name}}',
            'Hi {{affiliate.name}}, thanks for applying to the affiliate program of {{store.name}}. The store reviews each application; if you are approved, we email you the link to your private page.',
            $this->common($affiliate), "affiliate:{$affiliate->id}:applied", 'affiliates',
        );
        $owner = $this->contacts->ownerOf($affiliate->store_id);
        if ($owner !== null) {
            $this->notifications->send(
                NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::User, $owner->id, $owner->email,
                'New affiliate application: {{affiliate.name}}',
                '{{affiliate.name}} ({{affiliate.email}}) applied to your affiliate program. Review it under Marketing, Affiliates.',
                [...$this->common($affiliate), 'affiliate.email' => $affiliate->email], "affiliate:{$affiliate->id}:applied:owner", 'affiliates',
            );
        }
    }

    /** @return array<string, scalar> */
    private function common(Affiliate $affiliate): array
    {
        $store = Store::query()->withoutGlobalScopes()->findOrFail($affiliate->store_id);

        return ['store.name' => $store->name, 'affiliate.name' => $affiliate->name, 'affiliate.code' => $affiliate->code];
    }

    private function storefront(Affiliate $affiliate): string
    {
        $store = Store::query()->withoutGlobalScopes()->findOrFail($affiliate->store_id);

        return rtrim($this->seo->forStoreHome($store)->canonicalUrl, '/');
    }
}
