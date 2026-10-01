<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\InvitationStatus;
use App\Domain\Identity\Models\MembershipStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\StoreInvitation;
use App\Domain\Identity\Models\StoreMembership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\StoreTeamService;
use App\Domain\Identity\Services\TeamActionRefusedException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Module 02 §18–19, §29 — the store team (staff API, current store only).
 * Members are addressed by their user public id, invitations by theirs,
 * roles by slug. RoleGrants inside StoreTeamService decides which roles
 * and members the signed-in person may touch; refusals are answered 422
 * with the reason under the relevant field.
 */
final class TeamController
{
    // Resolved per call, not injected once: a controller instance can outlive a request (tests, Octane),
    // and these services hold the request's TenantContext.
    private function team(): StoreTeamService
    {
        return app(StoreTeamService::class);
    }

    private function grants(): RoleGrants
    {
        return app(RoleGrants::class);
    }

    /** Seats, the caller's abilities and the roles they may give, for the team page. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('team.view');

        return response()->json(['data' => [
            'seats' => $this->team()->seats(),
            'abilities' => ['invite' => Gate::forUser($user)->allows('team.invite'), 'manage' => Gate::forUser($user)->allows('team.manage')],
            'roles' => Role::query()->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    'slug' => $role->slug, 'name' => $role->name, 'is_system' => $role->is_system,
                    'grantable' => $this->grants()->canGrant($user, $role),
                ])->values(),
        ]]);
    }

    public function members(Request $request): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('team.view');

        $members = StoreMembership::query()->with(['user', 'role'])
            ->orderByRaw("FIELD(status, 'active', 'suspended', 'revoked')")->orderBy('id')->get();

        return response()->json(['data' => $members->map(fn (StoreMembership $m) => $this->member($m, $user))->values()]);
    }

    public function invitations(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.view');

        $invitations = StoreInvitation::query()->with(['role', 'invitedBy'])->where('status', InvitationStatus::Pending)->latest('id')->get();

        return response()->json(['data' => $invitations->map(fn (StoreInvitation $i) => $this->invitation($i))->values()]);
    }

    public function invite(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.invite');
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
        ]);

        return $this->refusable(fn () => response()->json(
            ['data' => $this->invitation($this->team()->invite($request->user(), $validated['email'], $this->role($validated['role']))->load(['role', 'invitedBy']))],
            201,
        ));
    }

    public function resend(Request $request, StoreInvitation $invitation): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.invite');

        return $this->refusable(fn () => response()->json(['data' => $this->invitation($this->team()->resend($request->user(), $invitation)->load(['role', 'invitedBy']))]));
    }

    public function revoke(Request $request, StoreInvitation $invitation): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.invite');

        return $this->refusable(fn () => response()->json(['data' => $this->invitation($this->team()->revoke($request->user(), $invitation)->load(['role', 'invitedBy']))]));
    }

    public function changeRole(Request $request, string $member): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.manage');
        $validated = $request->validate(['role' => ['required', 'string', 'max:255']]);
        $membership = $this->membership($member);

        return $this->refusable(fn () => response()->json(['data' => $this->member($this->team()->changeRole($request->user(), $membership, $this->role($validated['role'])), $request->user())]));
    }

    public function suspend(Request $request, string $member): JsonResponse
    {
        return $this->statusAction($request, $member, 'suspend');
    }

    public function reactivate(Request $request, string $member): JsonResponse
    {
        return $this->statusAction($request, $member, 'reactivate');
    }

    public function remove(Request $request, string $member): JsonResponse
    {
        return $this->statusAction($request, $member, 'remove');
    }

    private function statusAction(Request $request, string $member, string $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('team.manage');
        $membership = $this->membership($member);

        return $this->refusable(fn () => response()->json(['data' => $this->member($this->team()->{$action}($request->user(), $membership), $request->user())]));
    }

    /** @param callable(): JsonResponse $action */
    private function refusable(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (TeamActionRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        } catch (SubscriptionInactiveException $e) {
            return response()->json(['message' => 'Your subscription is not active, so the team cannot change right now.', 'code' => 'subscription_inactive'], 403);
        }
    }

    private function role(string $slug): Role
    {
        return Role::query()->where('slug', $slug)->first()
            ?? throw ValidationException::withMessages(['role' => 'Choose one of your store\'s roles.']);
    }

    private function membership(string $userPublicId): StoreMembership
    {
        return StoreMembership::query()->with(['user', 'role'])
            ->whereHas('user', fn ($q) => $q->where('public_id', $userPublicId))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function member(StoreMembership $m, User $viewer): array
    {
        return [
            'id' => $m->user->public_id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'role' => $m->role ? ['slug' => $m->role->slug, 'name' => $m->role->name] : null,
            'status' => $m->status->value,
            'is_owner' => $this->grants()->isOwnerRole($m->role),
            'is_you' => $m->user_id === $viewer->id,
            'can_manage' => $m->status !== MembershipStatus::Revoked && $this->grants()->canActOn($viewer, $m),
            'joined_at' => $m->created_at?->toIso8601String(),
            'status_changed_at' => $m->status_changed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function invitation(StoreInvitation $i): array
    {
        return [
            'id' => $i->public_id,
            'email' => $i->email,
            'role' => ['slug' => $i->role->slug, 'name' => $i->role->name],
            'status' => $i->isOpen() ? 'pending' : ($i->status === InvitationStatus::Pending ? 'expired' : $i->status->value),
            'invited_by' => $i->invitedBy?->name,
            'expires_at' => $i->expires_at->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
