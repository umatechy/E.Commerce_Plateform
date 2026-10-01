============================================================
PHASE B27 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B27 — Store staff management (gap G1; Module 02 §6, §17–19, §29)

Implementation Summary:
A store can now build its team. The owner, or anyone the owner allows,
invites a person by email with a role; the person accepts and becomes a
member; members can be given another role, suspended, reactivated or
removed.

Invitations (Module 02 §18):
- The invitation email carries a link whose token sits in the URL
  fragment, so it never reaches the server logs.
- Only a hash of the token is stored. The email body is stored encrypted
  ("sealed") in notification_messages and is never returned by the API.
- Tokens expire (7 days by default, config/team.php), work once, belong
  to one store and one role, and can be revoked or re-sent. Re-sending
  replaces the token.
- A wrong, expired and revoked token all give the same answer.
- A new person creates an account while accepting. An existing account
  must be signed in as the invited address.
- Nobody can invite to a role that holds a permission they lack.

Members (Module 02 §19):
- Change role, suspend, reactivate, remove. Removal keeps history, and
  the person can be invited again.
- The owner's membership and one's own membership are protected.
- A member cannot change someone who holds more authority.

Roles (Module 02 §6, §17, §29):
- Every store has the seven predefined roles: Owner, Administrator,
  Manager, Staff, Order Manager, Inventory Manager, Content & Marketing.
  A data migration adds the four new ones to existing stores.
- Custom roles need the package feature. A role that someone holds
  cannot be deleted. Role names are unique per store. Changes are
  audited.
- Seats: the package's staff limit counts members and open invitations,
  not the owner.

New Components Implemented:
App\Domain\Identity — StoreInvitation, StoreMembership,
InvitationStatus, MembershipStatus, StoreTeamService, RoleGrants,
SystemRoles, TeamPolicy, TeamController, InvitationController.
Notifications — sealed message bodies (SealedNotificationTest).
New permission: users.manage.

API:
- /api/v1/team/summary, /team/members (+ PATCH, suspend, reactivate,
  DELETE), /team/invitations (+ resend, DELETE)
- public: /api/v1/invitations/{id}/lookup and /accept (rate limited)

Frontend:
- Team page (/team): seats, invite form, members, open invitations
- Accept-invitation page (/invitations/{id})

Database:
- store_invitations (new)
- store_user: status tracking columns
- notification_messages: sealed_body
- data migration: the four new predefined roles for existing stores

Tests:
tests/Feature/Team (17 tests) and SealedNotificationTest pass. Full
suite on 2026-09-30: 892 passed, 2 failed. Neither failure is in this
phase: one needs mysqldump on PATH (local environment), one is a test
that fails when a random SKU contains "50" (fixed in the next commit).

Requirements closed:
STORE-007, Module 02 §6, §17, §18, §19, §29 (staff part).

Not in this phase:
- A complete permission catalogue/matrix screen (RBAC-002, Module 02
  §16, §49) — the role editor UI comes with the admin UI work (G6).
- MFA and session management (G2).

Next:
G5 (store timezone), then G2 (security baseline).
