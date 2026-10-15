<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateProgram;
use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Promotions\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B57 (Module 28 §5–6, §9–10, §70, §90–91) — a store's affiliates.
 *
 * - The store adds someone (active at once) or approves an application
 *   from its storefront (§90: approval before anything counts).
 * - Each affiliate has a referral code, unique in the store, case-free,
 *   never a reserved word; optionally one of the store's coupons, so an
 *   order with that coupon is theirs (§10).
 * - The affiliate follows their results on a private portal link. Its
 *   token is kept only as a hash; making a new link ends the old one.
 * - An affiliate who is also a customer of the store is linked by email,
 *   for the self-referral check only (§41).
 */
final class AffiliateDirectory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AffiliateNotifier $notifier,
    ) {}

    /**
     * @param array{name: string, email: string, phone?: ?string, code?: ?string, coupon_id?: ?int} $data
     * @return array{0: Affiliate, 1: string} the affiliate and its portal token, shown once
     */
    public function add(User $by, AffiliateProgram $program, array $data): array
    {
        $email = mb_strtolower(trim($data['email']));
        $this->refuseIf(Affiliate::query()->where('email', $email)->exists(), 'email_taken', 'This email address is already an affiliate of your store.');

        return DB::transaction(function () use ($by, $program, $data, $email) {
            $affiliate = Affiliate::query()->create([
                'store_id' => $program->store_id, 'affiliate_program_id' => $program->id,
                'name' => trim($data['name']), 'email' => $email, 'phone' => $data['phone'] ?? null,
                'status' => 'active', 'source' => 'invited', 'code' => $this->code($data['code'] ?? null, $data['name']),
                'coupon_id' => $this->coupon($data['coupon_id'] ?? null, null), 'customer_id' => $this->customerId($email),
                'approved_at' => now(), 'approved_by' => $by->id,
            ]);
            $token = $this->issueToken($affiliate);
            $this->audit->record('affiliates.affiliate.added', ['code' => $affiliate->code], $affiliate, actor: $by);
            $this->notifier->welcome($affiliate, $token);

            return [$affiliate, $token];
        });
    }

    /**
     * An application from the storefront's join page (§91). Waits for the store.
     *
     * @param array{name: string, email: string, phone?: ?string, note?: ?string} $data
     */
    public function apply(AffiliateProgram $program, array $data): ?Affiliate
    {
        $email = mb_strtolower(trim($data['email']));
        if (Affiliate::query()->where('email', $email)->exists()) {
            return null; // the same answer as a new application: no address is confirmed to anyone
        }
        $version = $program->currentVersion;
        $affiliate = Affiliate::query()->create([
            'store_id' => $program->store_id, 'affiliate_program_id' => $program->id,
            'name' => trim($data['name']), 'email' => $email, 'phone' => $data['phone'] ?? null,
            'status' => 'pending', 'source' => 'applied', 'code' => $this->code(null, $data['name']),
            'customer_id' => $this->customerId($email), 'application_note' => $data['note'] ?? null,
            'terms_version' => $version?->version, 'terms_accepted_at' => now(),
        ]);
        $this->audit->record('affiliates.affiliate.applied', ['code' => $affiliate->code], $affiliate, $program->store_id);
        $this->notifier->applicationReceived($affiliate);

        return $affiliate;
    }

    public function approve(User $by, Affiliate $affiliate): string
    {
        $this->refuseIf($affiliate->status !== 'pending', 'not_pending', 'Only an application waiting for you can be approved.');

        return DB::transaction(function () use ($by, $affiliate) {
            $affiliate->update(['status' => 'active', 'approved_at' => now(), 'approved_by' => $by->id, 'status_reason' => null]);
            $token = $this->issueToken($affiliate);
            $this->audit->record('affiliates.affiliate.approved', ['code' => $affiliate->code], $affiliate, actor: $by);
            $this->notifier->welcome($affiliate, $token);

            return $token;
        });
    }

    public function reject(User $by, Affiliate $affiliate, ?string $reason): void
    {
        $this->refuseIf($affiliate->status !== 'pending', 'not_pending', 'Only an application waiting for you can be declined.');
        $affiliate->update(['status' => 'rejected', 'status_reason' => $reason]);
        $this->audit->record('affiliates.affiliate.rejected', ['reason' => $reason], $affiliate, actor: $by);
    }

    /** Paused: no new clicks or orders count, and no payout is made (§70). Earned commissions stay. */
    public function pause(User $by, Affiliate $affiliate, ?string $reason): void
    {
        $this->refuseIf($affiliate->status !== 'active', 'not_active', 'Only an active affiliate can be paused.');
        $affiliate->update(['status' => 'paused', 'status_reason' => $reason]);
        $this->audit->record('affiliates.affiliate.paused', ['reason' => $reason], $affiliate, actor: $by);
    }

    public function resume(User $by, Affiliate $affiliate): void
    {
        $this->refuseIf($affiliate->status !== 'paused', 'not_paused', 'This affiliate is not paused.');
        $affiliate->update(['status' => 'active', 'status_reason' => null]);
        $this->audit->record('affiliates.affiliate.resumed', [], $affiliate, actor: $by);
    }

    /** @param array{name: string, phone?: ?string, code: string, coupon_id?: ?int} $data */
    public function update(User $by, Affiliate $affiliate, array $data): void
    {
        $code = AffiliateRules::normalizeCode($data['code']);
        $this->refuseIf(! AffiliateRules::validCode($code), 'code_invalid', 'A code is 4–20 letters and digits, and cannot be a reserved word.');
        $this->refuseIf(Affiliate::query()->where('code', $code)->where('id', '!=', $affiliate->id)->exists(), 'code_taken', 'Another affiliate has this code.');
        $before = $affiliate->only(['name', 'phone', 'code', 'coupon_id']);
        $affiliate->update(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null, 'code' => $code, 'coupon_id' => $this->coupon($data['coupon_id'] ?? null, $affiliate)]);
        $this->audit->record('affiliates.affiliate.updated', ['before' => $before, 'after' => $affiliate->only(['name', 'phone', 'code', 'coupon_id'])], $affiliate, actor: $by);
    }

    /** A new private link; the one before stops working. */
    public function newPortalLink(User $by, Affiliate $affiliate): string
    {
        $this->refuseIf(! in_array($affiliate->status, ['active', 'paused'], true), 'not_active', 'Only an approved affiliate has a portal.');
        $token = $this->issueToken($affiliate);
        $this->audit->record('affiliates.affiliate.portal_link_renewed', [], $affiliate, actor: $by);

        return $token;
    }

    public function byToken(string $token): ?Affiliate
    {
        if (strlen($token) < 40) {
            return null;
        }

        return Affiliate::query()->where('portal_token_hash', hash('sha256', $token))->whereIn('status', ['active', 'paused'])->first();
    }

    /**
     * Where the affiliate wants to be paid (§36). Stored encrypted; shown masked.
     *
     * @param array{account_title: string, account_number: string, bank?: ?string} $details
     */
    public function setPayoutDetails(Affiliate $affiliate, string $method, array $details, ?User $by): void
    {
        $affiliate->update(['payout_method' => $method, 'payout_details' => [
            'account_title' => trim($details['account_title']), 'account_number' => preg_replace('/\s+/', '', $details['account_number']), 'bank' => $details['bank'] ?? null,
        ]]);
        $this->audit->record('affiliates.affiliate.payout_details_changed', ['method' => $method, 'by' => $by === null ? 'affiliate' : 'store'], $affiliate, $affiliate->store_id, $by);
    }

    /** @return array{method: ?string, account_title: ?string, account: ?string, bank: ?string} */
    public static function maskedPayout(Affiliate $affiliate): array
    {
        $details = (array) $affiliate->payout_details;
        $number = (string) ($details['account_number'] ?? '');

        return [
            'method' => $affiliate->payout_method,
            'account_title' => $details['account_title'] ?? null,
            'account' => $number === '' ? null : str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4),
            'bank' => $details['bank'] ?? null,
        ];
    }

    /**
     * The full account, to send a payout to (§36). Only to someone allowed
     * to pay, after step-up on the route, and every look is audited.
     *
     * @return array{method: string|null, account_title: string|null, account_number: string|null, bank: string|null}
     */
    public function revealPayout(User $by, Affiliate $affiliate, string $why): array
    {
        $details = (array) $affiliate->payout_details;
        $this->audit->record('affiliates.payout_details.revealed', ['why' => $why], $affiliate, actor: $by);

        return [
            'method' => $affiliate->payout_method,
            'account_title' => $details['account_title'] ?? null,
            'account_number' => $details['account_number'] ?? null,
            'bank' => $details['bank'] ?? null,
        ];
    }

    private function issueToken(Affiliate $affiliate): string
    {
        $token = Str::random(48);
        $affiliate->update(['portal_token_hash' => hash('sha256', $token)]);

        return $token;
    }

    private function code(?string $wanted, string $name): string
    {
        if ($wanted !== null && trim($wanted) !== '') {
            $code = AffiliateRules::normalizeCode($wanted);
            $this->refuseIf(! AffiliateRules::validCode($code), 'code_invalid', 'A code is 4–20 letters and digits, and cannot be a reserved word.');
            $this->refuseIf(Affiliate::query()->where('code', $code)->exists(), 'code_taken', 'Another affiliate has this code.');

            return $code;
        }
        $stem = substr(AffiliateRules::normalizeCode(Str::ascii($name)), 0, 8);
        $stem = strlen($stem) < 3 ? 'REF' : $stem;
        do {
            $code = $stem.random_int(10, 9999);
        } while (! AffiliateRules::validCode($code) || Affiliate::query()->where('code', $code)->exists());

        return $code;
    }

    /** A coupon of this store, not already another affiliate's (§10). */
    private function coupon(?int $couponId, ?Affiliate $self): ?int
    {
        if ($couponId === null) {
            return null;
        }
        $this->refuseIf(! Coupon::query()->whereKey($couponId)->exists(), 'coupon_missing', 'Choose one of your store’s coupon codes.');
        $this->refuseIf(Affiliate::query()->where('coupon_id', $couponId)->when($self, fn ($q) => $q->where('id', '!=', $self->id))->exists(), 'coupon_taken', 'Another affiliate already has this coupon code.');

        return $couponId;
    }

    private function customerId(string $email): ?int
    {
        return Customer::query()->where('email', $email)->value('id');
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new AffiliateRefused($code, $message);
        }
    }
}
