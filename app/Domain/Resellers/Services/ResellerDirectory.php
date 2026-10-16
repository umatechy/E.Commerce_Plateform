<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Affiliates\Support\PayoutAccount;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B58 (Module 28 §6, §70, §72, §92) — who the resellers are and the
 * state of each.
 *
 * Onboarding: Umar Techy invites a business, or a business applies on the
 * public partner page and Umar Techy approves it with a tier. Either way the
 * business gets an invitation link (token hashed, 14 days, emailed) and
 * accepts it with the account whose email matches; that account signs in to
 * the partner portal with two-step sign-in.
 *
 * Pausing stops new stores; terminating also stops commissions on invoices
 * issued afterwards and closes the portal (§72). Stores stay their owners'.
 */
final class ResellerDirectory
{
    private const INVITATION_DAYS = 14;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResellerNotifier $notifier,
    ) {}

    /**
     * Umar Techy invites a business. Returns the reseller and its invitation link (shown once).
     *
     * @param array{business_name: string, contact_name: string, email: string, phone?: ?string, country?: ?string, website?: ?string, code?: ?string} $data
     * @return array{0: Reseller, 1: string}
     */
    public function invite(User $by, ResellerTier $tier, array $data): array
    {
        $this->refuseIf(! $tier->is_active, 'tier_inactive', 'Choose an active tier.');
        $email = $this->email($data['email']);
        $this->refuseIf(Reseller::query()->where('email', $email)->exists(), 'email_taken', 'A reseller with this email already exists.');

        return DB::transaction(function () use ($by, $tier, $data, $email) {
            $reseller = Reseller::query()->create([
                ...$this->profile($data), 'email' => $email, 'reseller_tier_id' => $tier->id, 'code' => $this->code($data['code'] ?? null, $data['business_name']),
                'status' => 'invited', 'source' => 'invited', 'approved_by' => $by->id, 'approved_at' => now(),
            ]);
            $url = $this->sendInvitation($reseller);
            $this->audit->record('resellers.reseller.invited', ['tier' => $tier->name, 'code' => $reseller->code], $reseller, actor: $by, platform: true);

            return [$reseller, $url];
        });
    }

    /**
     * A business applies on the public partner page (§92). The same answer
     * whether or not the address is known, so nobody learns who is a partner.
     *
     * @param array{business_name: string, contact_name: string, email: string, phone?: ?string, country?: ?string, website?: ?string, note?: ?string} $data
     */
    public function apply(array $data): ?Reseller
    {
        $email = $this->email($data['email']);
        if (Reseller::query()->where('email', $email)->exists()) {
            return null;
        }
        $reseller = Reseller::query()->create([
            ...$this->profile($data), 'email' => $email, 'code' => $this->code(null, $data['business_name']),
            'status' => 'pending', 'source' => 'applied', 'application_note' => $data['note'] ?? null,
        ]);
        $this->audit->record('resellers.reseller.applied', ['code' => $reseller->code], $reseller, platform: true);
        $this->notifier->applicationReceived($reseller);

        return $reseller;
    }

    /** Approve an application with a tier: the business is invited to the portal. Returns the link (shown once). */
    public function approve(User $by, Reseller $reseller, ResellerTier $tier): string
    {
        $this->refuseIf($reseller->status !== 'pending', 'not_pending', 'Only an application can be approved.');
        $this->refuseIf(! $tier->is_active, 'tier_inactive', 'Choose an active tier.');

        return DB::transaction(function () use ($by, $reseller, $tier) {
            $reseller->update(['status' => 'invited', 'reseller_tier_id' => $tier->id, 'approved_by' => $by->id, 'approved_at' => now(), 'status_reason' => null]);
            $url = $this->sendInvitation($reseller);
            $this->audit->record('resellers.reseller.approved', ['tier' => $tier->name], $reseller, actor: $by, platform: true);

            return $url;
        });
    }

    public function reject(User $by, Reseller $reseller, ?string $reason): void
    {
        $this->refuseIf($reseller->status !== 'pending', 'not_pending', 'Only an application can be declined.');
        $reseller->update(['status' => 'rejected', 'status_reason' => $reason]);
        $this->audit->record('resellers.reseller.rejected', ['reason' => $reason], $reseller, actor: $by, platform: true);
    }

    /** A new invitation link; the old one stops working. */
    public function reinvite(User $by, Reseller $reseller): string
    {
        $this->refuseIf($reseller->status !== 'invited', 'not_invited', 'Only a reseller who has not accepted yet can be invited again.');
        $url = $this->sendInvitation($reseller);
        $this->audit->record('resellers.reseller.reinvited', [], $reseller, actor: $by, platform: true);

        return $url;
    }

    /** The invitation behind a link, if it can still be accepted. */
    public function invitation(string $token): ?Reseller
    {
        if (strlen($token) < 40) {
            return null;
        }
        $reseller = Reseller::query()->where('invitation_token_hash', hash('sha256', $token))->where('status', 'invited')->first();

        return $reseller !== null && $reseller->invitation_expires_at?->isFuture() ? $reseller : null;
    }

    /**
     * The invited business accepts with the account of the invited address
     * (§92 "portal access"). One account is one reseller.
     */
    public function accept(string $token, User $user): Reseller
    {
        return DB::transaction(function () use ($token, $user) {
            $reseller = $this->invitation($token) ?? throw new ResellerRefused('invitation_invalid', 'This invitation does not work any more. Ask Umar Techy for a new one.');
            $reseller = Reseller::query()->lockForUpdate()->findOrFail($reseller->id);
            $this->refuseIf(mb_strtolower($user->email) !== $reseller->email, 'email_mismatch', 'Sign in with the account of '.$reseller->email.' to accept this invitation.');
            $this->refuseIf(Reseller::query()->where('user_id', $user->id)->where('id', '!=', $reseller->id)->exists(), 'already_reseller', 'This account already belongs to another reseller.');
            $reseller->update(['user_id' => $user->id, 'status' => 'active', 'terms_accepted_at' => now(), 'invitation_token_hash' => null, 'invitation_expires_at' => null]);
            $this->audit->record('resellers.reseller.joined', [], $reseller, actor: $user, platform: true);

            return $reseller;
        });
    }

    public function pause(User $by, Reseller $reseller, ?string $reason): void
    {
        $this->refuseIf($reseller->status !== 'active', 'not_active', 'Only an active reseller can be paused.');
        $reseller->update(['status' => 'paused', 'status_reason' => $reason]);
        $this->audit->record('resellers.reseller.paused', ['reason' => $reason], $reseller, actor: $by, platform: true);
    }

    public function resume(User $by, Reseller $reseller): void
    {
        $this->refuseIf($reseller->status !== 'paused', 'not_paused', 'Only a paused reseller can be resumed.');
        $reseller->update(['status' => 'active', 'status_reason' => null]);
        $this->audit->record('resellers.reseller.resumed', [], $reseller, actor: $by, platform: true);
    }

    /**
     * §72: no new stores or referrals, no commissions on invoices issued from
     * now on, the portal closes. Earned commissions are still paid; the
     * stores stay with their owners.
     */
    public function terminate(User $by, Reseller $reseller, string $reason): void
    {
        $this->refuseIf(in_array($reseller->status, ['terminated', 'rejected'], true), 'already_closed', 'This reseller is already closed.');
        $reseller->update(['status' => 'terminated', 'status_reason' => $reason, 'terminated_at' => now(), 'invitation_token_hash' => null, 'invitation_expires_at' => null]);
        $this->audit->record('resellers.reseller.terminated', ['reason' => $reason], $reseller, actor: $by, platform: true);
    }

    /**
     * A new tier applies to stores attributed from now on; stores already
     * attributed keep the terms they came with (§17).
     *
     * @param array{business_name: string, contact_name: string, phone?: ?string, country?: ?string, website?: ?string} $data
     */
    public function update(User $by, Reseller $reseller, array $data, ?ResellerTier $tier): void
    {
        $this->refuseIf($tier !== null && ! $tier->is_active && $tier->id !== $reseller->reseller_tier_id, 'tier_inactive', 'Choose an active tier.');
        $before = $reseller->reseller_tier_id;
        $reseller->update([...$this->profile($data), ...($tier !== null ? ['reseller_tier_id' => $tier->id] : [])]);
        $this->audit->record('resellers.reseller.updated', ['tier_changed' => $tier !== null && $tier->id !== $before, 'tier' => $tier?->name], $reseller, actor: $by, platform: true);
    }

    /**
     * Where Umar Techy pays the reseller (§36): encrypted, shown masked.
     *
     * @param array{account_title: string, account_number: string, bank?: ?string} $details
     */
    public function setPayoutDetails(Reseller $reseller, string $method, array $details, User $by): void
    {
        $reseller->update(['payout_method' => $method, 'payout_details' => PayoutAccount::normalize($details)]);
        $this->audit->record('resellers.reseller.payout_details_changed', ['method' => $method, 'by' => $by->id === $reseller->user_id ? 'reseller' : 'umar_techy'], $reseller, actor: $by, platform: true);
    }

    /**
     * The full account, to send a payout to: step-up on the route, audited every time.
     *
     * @return array{method: ?string, account_title: ?string, account_number: ?string, bank: ?string}
     */
    public function revealPayout(User $by, Reseller $reseller, string $why): array
    {
        $this->audit->record('resellers.payout_details.revealed', ['why' => $why], $reseller, actor: $by, platform: true);

        return PayoutAccount::full($reseller->payout_method, $reseller->payout_details);
    }

    /** The reseller this account belongs to, whatever its state. */
    public function forUser(User $user): ?Reseller
    {
        return Reseller::query()->with('tier')->where('user_id', $user->id)->first();
    }

    private function sendInvitation(Reseller $reseller): string
    {
        $token = Str::random(48);
        $reseller->update(['invitation_token_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS)]);
        $url = $this->notifier->invitationUrl($token);
        $this->notifier->invitation($reseller, $url);

        return $url;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function profile(array $data): array
    {
        return [
            'business_name' => trim((string) $data['business_name']), 'contact_name' => trim((string) $data['contact_name']),
            'phone' => $data['phone'] ?? null, 'country' => isset($data['country']) && $data['country'] !== '' ? strtoupper((string) $data['country']) : null,
            'website' => $data['website'] ?? null,
        ];
    }

    private function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** A reseller's code: what their sign-up link carries (`/register?partner=CODE`). */
    private function code(?string $wanted, string $name): string
    {
        if ($wanted !== null && trim($wanted) !== '') {
            $code = AffiliateRules::normalizeCode($wanted);
            $this->refuseIf(! AffiliateRules::validCode($code), 'code_invalid', 'A code is 4–20 letters and digits, and cannot be a reserved word.');
            $this->refuseIf(Reseller::query()->where('code', $code)->exists(), 'code_taken', 'Another reseller has this code.');

            return $code;
        }
        $stem = substr(AffiliateRules::normalizeCode(Str::ascii($name)), 0, 8);
        $stem = strlen($stem) < 3 ? 'PARTNER' : $stem;
        do {
            $code = $stem.random_int(10, 9999);
        } while (Reseller::query()->where('code', $code)->exists());

        return $code;
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new ResellerRefused($code, $message);
        }
    }
}
