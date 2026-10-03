<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 10 §9, §13, §67 and SRS AUTH-002: a customer proves their email
 * address with a one-time link.
 *
 * - The link holds a random token; only its SHA-256 is stored. It works
 *   once, for 24 hours, for the address it was sent to: if the customer
 *   changed their email meanwhile, the old link does nothing.
 * - Asking again within a minute sends nothing new (a mailbox cannot be
 *   flooded through this).
 * - A verified address lets the customer's guest orders join the account
 *   (GuestOrderLinker).
 */
final class CustomerEmailVerification
{
    private const TTL_HOURS = 24;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SeoResolver $seo,
        private readonly GuestOrderLinker $linker,
        private readonly AuditLogger $audit,
    ) {}

    /** @return bool false when the address is already verified or a link was sent less than a minute ago */
    public function send(Customer $customer, Store $store): bool
    {
        if ($customer->email_verified_at !== null) {
            return false;
        }

        $recent = DB::table('customer_email_verifications')
            ->where('customer_id', $customer->id)
            ->where('created_at', '>', now()->subMinute())
            ->exists();
        if ($recent) {
            return false;
        }

        $token = Str::random(64);
        $id = DB::transaction(function () use ($customer, $token) {
            DB::table('customer_email_verifications')->where('customer_id', $customer->id)->whereNull('used_at')->delete();

            return DB::table('customer_email_verifications')->insertGetId([
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'email' => mb_strtolower($customer->email),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'created_at' => now(),
            ]);
        });

        $link = rtrim($this->seo->forStoreHome($store)->canonicalUrl, '/').'/account/verify-email?'.http_build_query([
            'token' => $token,
            'email' => $customer->email,
        ]);

        $this->notifications->send(
            NotificationMessageType::Security, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Confirm your email address for {{store.name}}',
            'Hi {{customer.name}}, please confirm that this is your email address by opening this link within 24 hours: {{verify.link}} If you did not create an account with {{store.name}}, you can ignore this email.',
            ['store.name' => $store->name, 'customer.name' => $customer->name],
            "customer-email-verification:{$id}",
            'customer.email_verification_requested',
            secretVariables: ['verify.link' => $link],
        );

        $this->audit->record('customer.email_verification_requested', [], $customer, $customer->store_id, $customer);

        return true;
    }

    /** @return Customer|null the verified customer, or null when the link is wrong, used, expired or for another address */
    public function verify(string $email, string $token): ?Customer
    {
        $customer = DB::transaction(function () use ($email, $token) {
            $row = DB::table('customer_email_verifications')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $customer = $row !== null ? Customer::query()->whereKey($row->customer_id)->first() : null;

            if ($row === null || $customer === null || $row->used_at !== null
                || now()->greaterThan($row->expires_at)
                || ! hash_equals((string) $row->email, mb_strtolower($email))
                || ! hash_equals(mb_strtolower($customer->email), mb_strtolower($email))
                || $customer->erased_at !== null) {
                return null;
            }

            $customer->forceFill(['email_verified_at' => $customer->email_verified_at ?? now()])->save();
            DB::table('customer_email_verifications')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('customer_email_verifications')->where('customer_id', $customer->id)->whereNull('used_at')->delete();

            return $customer;
        });

        if ($customer !== null) {
            $this->audit->record('customer.email_verified', [], $customer, $customer->store_id, $customer);
            $this->linker->link($customer);
        }

        return $customer;
    }
}
