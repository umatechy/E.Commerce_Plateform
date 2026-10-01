<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\InvitationStatus;
use App\Domain\Identity\Models\MembershipStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\StoreInvitation;
use App\Domain\Identity\Models\StoreMembership;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Packages\Models\EntitlementEnforcement;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 02 §18–19, §21, §29 — a store's team: invitations, role changes,
 * suspension, reactivation and removal. The only writer of
 * store_invitations and of store_user status/role changes. Every action
 * runs in the current tenant, checks RoleGrants and is audited.
 *
 * Seats: `max_staff_accounts` (Module 04 §9) counts active and suspended
 * members plus open invitations, and excludes the Owner. The blueprint
 * does not say whether the Owner occupies a seat; excluding them is the
 * documented choice here.
 */
final class StoreTeamService
{
    public const SEAT_LIMIT_KEY = 'max_staff_accounts';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly RoleGrants $grants,
    ) {}

    public function invite(User $actor, string $email, Role $role): StoreInvitation
    {
        $email = Str::lower(trim($email));
        $this->entitlements->assertSubscriptionActive();
        $this->assertCanGrant($actor, $role);
        $this->expireStaleInvitations();

        $member = StoreMembership::query()->whereHas('user', fn ($q) => $q->where('email', $email))->first();
        if ($member !== null && $member->status !== MembershipStatus::Revoked) {
            throw new TeamActionRefusedException('already_member', 'This person is already on your team.', 'email');
        }
        if (StoreInvitation::query()->where('pending_email', $email)->exists()) {
            throw new TeamActionRefusedException('already_invited', 'This person already has an open invitation. Resend it instead.', 'email');
        }
        $this->assertSeatAvailable();

        $token = Str::random(64);
        $invitation = StoreInvitation::query()->create([
            'email' => $email,
            'pending_email' => $email,
            'role_id' => $role->id,
            'token_hash' => hash('sha256', $token),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(config('team.invitation_ttl_days')),
            'invited_by_user_id' => $actor->id,
        ]);

        $this->sendInvitation($invitation, $token, $actor, 'sent');
        $this->audit->record('team.invitation_sent', ['email' => $email, 'role' => $role->slug], $invitation);

        return $invitation;
    }

    /** A fresh token and expiry; the old link stops working. */
    public function resend(User $actor, StoreInvitation $invitation): StoreInvitation
    {
        $this->assertOpen($invitation);
        $this->assertCanGrant($actor, $invitation->role);

        $token = Str::random(64);
        $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(config('team.invitation_ttl_days'))]);

        $this->sendInvitation($invitation, $token, $actor, 'resent:'.now()->getTimestampMs());
        $this->audit->record('team.invitation_resent', ['email' => $invitation->email], $invitation);

        return $invitation;
    }

    public function revoke(User $actor, StoreInvitation $invitation): StoreInvitation
    {
        $this->assertOpen($invitation);
        $this->assertCanGrant($actor, $invitation->role);

        $invitation->update(['status' => InvitationStatus::Revoked, 'pending_email' => null, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);
        $this->audit->record('team.invitation_revoked', ['email' => $invitation->email], $invitation);

        return $invitation;
    }

    public function changeRole(User $actor, StoreMembership $member, Role $role): StoreMembership
    {
        $this->assertCanActOn($actor, $member);
        $this->assertCanGrant($actor, $role);
        $from = $member->role?->slug;

        $member->update(['role_id' => $role->id]);
        $this->audit->record('team.member_role_changed', ['user' => $member->user->public_id, 'from' => $from, 'to' => $role->slug], $member->user);

        return $member->load('role');
    }

    public function suspend(User $actor, StoreMembership $member): StoreMembership
    {
        return $this->transition($actor, $member, MembershipStatus::Active, MembershipStatus::Suspended, 'team.member_suspended');
    }

    public function reactivate(User $actor, StoreMembership $member): StoreMembership
    {
        $this->entitlements->assertSubscriptionActive();

        return $this->transition($actor, $member, MembershipStatus::Suspended, MembershipStatus::Active, 'team.member_reactivated');
    }

    /** Removes the person from the team. Their past actions keep pointing at their user row (Module 02 §19). */
    public function remove(User $actor, StoreMembership $member): StoreMembership
    {
        $this->assertCanActOn($actor, $member);
        if ($member->status === MembershipStatus::Revoked) {
            throw new TeamActionRefusedException('already_removed', 'This person is no longer on your team.');
        }

        return $this->transition($actor, $member, $member->status, MembershipStatus::Revoked, 'team.member_removed');
    }

    /** @return array{used: int, limit: ?int} */
    public function seats(): array
    {
        return ['used' => $this->seatsUsed(), 'limit' => $this->entitlements->limitFor(self::SEAT_LIMIT_KEY)];
    }

    /**
     * Finds an open invitation by its public id and token (the token alone
     * proves the invitee received the email). Every failure looks the same.
     */
    public function findOpen(string $publicId, string $token): StoreInvitation
    {
        $invitation = StoreInvitation::query()->withoutTenantScope()->where('public_id', $publicId)->first();

        if ($invitation === null || ! hash_equals($invitation->token_hash, hash('sha256', $token)) || ! $invitation->isOpen()) {
            throw new InvitationUnavailableException();
        }

        // The server-verified invitation decides the tenant for the rest of this
        // request, as a queued job does from its own row.
        $this->tenant->resolveToStore($invitation->store_id);

        return $invitation->load('role');
    }

    /**
     * Joins the invitee to the store. An existing account must be signed in
     * as the invited address; otherwise a new account is created for it
     * (the invitation email verifies the address).
     *
     * @param array{name?: ?string, password?: ?string} $newAccount
     */
    public function accept(string $publicId, string $token, ?User $signedIn, array $newAccount): User
    {
        $invitation = $this->findOpen($publicId, $token);

        return DB::transaction(function () use ($invitation, $signedIn, $newAccount) {
            $invitation = StoreInvitation::query()->lockForUpdate()->with('role')->findOrFail($invitation->id);
            if (! $invitation->isOpen()) {
                throw new InvitationUnavailableException();
            }

            $this->entitlements->assertSubscriptionActive();
            $this->assertSeatAvailable(0); // the invitation already holds its seat; a downgrade since then may not allow it

            $user = $this->invitee($invitation, $signedIn, $newAccount);
            $member = StoreMembership::query()->where('user_id', $user->id)->first();

            if ($member !== null && $member->status !== MembershipStatus::Revoked) {
                throw new TeamActionRefusedException('already_member', 'You are already on this team.');
            }

            if ($member === null) {
                StoreMembership::query()->create(['user_id' => $user->id, 'role_id' => $invitation->role_id, 'status' => MembershipStatus::Active]);
            } else {
                $member->update(['role_id' => $invitation->role_id, 'status' => MembershipStatus::Active, 'status_changed_at' => now(), 'status_changed_by_user_id' => $user->id]);
            }

            $invitation->update(['status' => InvitationStatus::Accepted, 'pending_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $user->id]);
            $this->audit->record('team.invitation_accepted', ['email' => $invitation->email, 'role' => $invitation->role->slug], $invitation, $invitation->store_id, $user);

            return $user;
        });
    }

    /** @param array{name?: ?string, password?: ?string} $newAccount */
    private function invitee(StoreInvitation $invitation, ?User $signedIn, array $newAccount): User
    {
        $existing = User::query()->where('email', $invitation->email)->first();

        if ($signedIn !== null && Str::lower($signedIn->email) !== $invitation->email) {
            throw new TeamActionRefusedException('wrong_account', "This invitation is for {$invitation->email}. Sign out and open the link again, or sign in with that address.");
        }

        if ($existing !== null) {
            if ($signedIn === null) {
                throw new TeamActionRefusedException('sign_in_required', 'You already have an account. Sign in with it to accept this invitation.');
            }
            if (! $existing->is_active) {
                throw new TeamActionRefusedException('account_inactive', 'This account is deactivated. Contact the platform team.');
            }

            return $existing;
        }

        if (empty($newAccount['name']) || empty($newAccount['password'])) {
            throw new TeamActionRefusedException('account_details_required', 'Enter your name and a password to create your account.', 'name');
        }

        $user = User::query()->create(['name' => $newAccount['name'], 'email' => $invitation->email, 'password' => $newAccount['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function transition(User $actor, StoreMembership $member, MembershipStatus $from, MembershipStatus $to, string $action): StoreMembership
    {
        $this->assertCanActOn($actor, $member);
        if ($member->status !== $from) {
            throw new TeamActionRefusedException('invalid_status', match ($to) {
                MembershipStatus::Suspended => 'Only an active member can be suspended.',
                MembershipStatus::Active => 'Only a suspended member can be reactivated.',
                default => 'This change is not possible.',
            });
        }

        $member->update(['status' => $to, 'status_changed_at' => now(), 'status_changed_by_user_id' => $actor->id]);
        $this->audit->record($action, ['user' => $member->user->public_id, 'role' => $member->role?->slug], $member->user);

        return $member;
    }

    private function sendInvitation(StoreInvitation $invitation, string $token, User $actor, string $keySuffix): void
    {
        $store = Store::query()->findOrFail($invitation->store_id);
        // The token rides in the #fragment: browsers never send it, so it stays out of logs and Referer headers.
        $link = rtrim((string) config('app.url'), '/')."/invitations/{$invitation->public_id}#token={$token}";

        $this->notifications->send(
            NotificationMessageType::Administrative, NotificationChannel::Email,
            RecipientType::User, null, $invitation->email,
            'You are invited to join {{store.name}}',
            'Hi, {{inviter.name}} invited you to join the {{store.name}} team as {{role.name}}. Accept the invitation within {{ttl}} days: {{invitation.link}} If you were not expecting this, you can ignore this email.',
            ['store.name' => $store->name, 'inviter.name' => $actor->name, 'role.name' => $invitation->role->name, 'ttl' => (string) config('team.invitation_ttl_days')],
            "team-invitation:{$invitation->id}:{$keySuffix}",
            'team.invitation_sent',
            secretVariables: ['invitation.link' => $link],
        );
    }

    private function seatsUsed(): int
    {
        $members = StoreMembership::query()
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->where(fn ($q) => $q->whereNull('role_id')->orWhereDoesntHave('role', fn ($r) => $r->where('slug', 'owner')))
            ->count();

        return $members + StoreInvitation::query()->where('status', InvitationStatus::Pending)->where('expires_at', '>', now())->count();
    }

    private function assertSeatAvailable(int $additional = 1): void
    {
        $limit = $this->entitlements->limitFor(self::SEAT_LIMIT_KEY);

        if ($limit === null || $this->entitlements->enforcementFor(self::SEAT_LIMIT_KEY) !== EntitlementEnforcement::Hard) {
            return;
        }

        if ($this->seatsUsed() + $additional > $limit) {
            throw new TeamActionRefusedException('staff_limit_reached', "Your package allows {$limit} team members. Remove someone or upgrade to add more.", 'email');
        }
    }

    /** Pending invitations past their expiry become Expired, freeing the address for a new one. */
    private function expireStaleInvitations(): void
    {
        StoreInvitation::query()->where('status', InvitationStatus::Pending)->where('expires_at', '<=', now())
            ->update(['status' => InvitationStatus::Expired->value, 'pending_email' => null]);
    }

    private function assertOpen(StoreInvitation $invitation): void
    {
        if (! $invitation->isOpen()) {
            throw new TeamActionRefusedException('invitation_closed', 'This invitation is no longer open.');
        }
    }

    private function assertCanGrant(User $actor, Role $role): void
    {
        if (! $this->grants->canGrant($actor, $role)) {
            throw new TeamActionRefusedException('role_not_grantable', $this->grants->isOwnerRole($role)
                ? 'The owner role cannot be given to someone else.'
                : 'You can only give roles whose permissions you have yourself.', 'role');
        }
    }

    private function assertCanActOn(User $actor, StoreMembership $member): void
    {
        if (! $this->grants->canActOn($actor, $member)) {
            throw new TeamActionRefusedException('member_protected', match (true) {
                $member->user_id === $actor->id => 'You cannot change your own membership.',
                $this->grants->isOwnerRole($member->role) => 'The store owner cannot be changed here.',
                default => 'This member has permissions you do not have.',
            });
        }
    }
}
