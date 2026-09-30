<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Services;

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
 * Phase B25 — storefront "forgot password".
 *
 *  - The request never reveals whether an account exists (the caller
 *    always answers 202); only registered, non-erased accounts of THIS
 *    store receive mail.
 *  - Tokens are 64 random characters; only their SHA-256 is stored.
 *    They expire after 60 minutes, work once, and a new request
 *    replaces any older unused one.
 *  - At most one mail per account per minute, on top of the route's
 *    per-IP throttle, so the endpoint cannot be used to flood an inbox.
 *  - A successful reset signs the customer out everywhere (all tokens
 *    revoked) and confirms the email address (the link proved it).
 */
final class CustomerPasswordResetService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SeoResolver $seo,
    ) {}

    public function request(Store $store, string $email, ?string $ip): void
    {
        $customer = Customer::query()
            ->where('email', $email)
            ->whereNotNull('password')
            ->whereNull('erased_at')
            ->first();

        if ($customer === null) {
            return;
        }

        $recent = DB::table('customer_password_resets')
            ->where('customer_id', $customer->id)
            ->where('created_at', '>', now()->subMinute())
            ->exists();

        if ($recent) {
            return;
        }

        $token = Str::random(64);

        $resetId = DB::transaction(function () use ($customer, $token, $ip) {
            DB::table('customer_password_resets')->where('customer_id', $customer->id)->whereNull('used_at')->delete();

            return DB::table('customer_password_resets')->insertGetId([
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'requested_ip' => $ip,
                'created_at' => now(),
            ]);
        });

        $link = rtrim($this->seo->forStoreHome($store)->canonicalUrl, '/').'/account/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $customer->email,
        ]);

        $this->notifications->send(
            NotificationMessageType::Security, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Reset your {{store.name}} password',
            'Hi {{customer.name}}, we received a request to reset your password. Use this link within 60 minutes to choose a new one: {{reset.link}} If you did not ask for this, you can ignore this email; your password stays the same.',
            ['store.name' => $store->name, 'customer.name' => $customer->name, 'reset.link' => $link],
            "customer-password-reset:{$resetId}",
            'customer.password_reset_requested',
        );

        app(AuditLogger::class)->record('customer.password_reset_requested', [], $customer, $customer->store_id);
    }

    /** @return bool false when the token is unknown, used, expired or for another account */
    public function reset(string $email, string $token, string $password): bool
    {
        return DB::transaction(function () use ($email, $token, $password) {
            $row = DB::table('customer_password_resets')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            $customer = $row !== null ? Customer::query()->whereKey($row->customer_id)->first() : null;

            if ($row === null || $customer === null || $row->used_at !== null
                || now()->greaterThan($row->expires_at)
                || ! hash_equals(mb_strtolower($customer->email), mb_strtolower($email))
                || $customer->erased_at !== null) {
                return false;
            }

            $customer->forceFill([
                'password' => $password, // the model's `hashed` cast hashes it
                'email_verified_at' => $customer->email_verified_at ?? now(),
            ])->save();
            $customer->tokens()->delete();

            DB::table('customer_password_resets')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('customer_password_resets')->where('customer_id', $customer->id)->whereNull('used_at')->delete();

            app(AuditLogger::class)->record('customer.password_reset', [], $customer, $customer->store_id, $customer);

            return true;
        });
    }
}
