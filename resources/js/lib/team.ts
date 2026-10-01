/** Phase G1 (Module 02 §18–19) — shapes and helpers for the team page. */
export type TeamRole = { slug: string; name: string; is_system: boolean; grantable: boolean };

export type TeamMember = {
  id: string;
  name: string;
  email: string;
  role: { slug: string; name: string } | null;
  status: 'active' | 'suspended' | 'revoked';
  is_owner: boolean;
  is_you: boolean;
  can_manage: boolean;
  joined_at: string | null;
  status_changed_at: string | null;
};

export type TeamInvitation = {
  id: string;
  email: string;
  role: { slug: string; name: string };
  status: 'pending' | 'expired' | 'accepted' | 'revoked';
  invited_by: string | null;
  expires_at: string;
  created_at: string | null;
};

export type TeamSummary = {
  seats: { used: number; limit: number | null };
  abilities: { invite: boolean; manage: boolean };
  roles: TeamRole[];
};

export const MEMBER_STATUS_LABELS: Record<TeamMember['status'], string> = {
  active: 'Active',
  suspended: 'Suspended',
  revoked: 'Removed',
};

/** "3 of 5 seats used", or "3 team members" when the package has no limit. */
export function seatText(seats: TeamSummary['seats']): string {
  return seats.limit === null ? `${seats.used} team member${seats.used === 1 ? '' : 's'}` : `${seats.used} of ${seats.limit} seats used`;
}

export function seatsFull(seats: TeamSummary['seats']): boolean {
  return seats.limit !== null && seats.used >= seats.limit;
}

/** Reads the invitation token from the URL fragment, which the browser never sends to the server. */
export function tokenFromHash(hash: string): string | null {
  const match = /(?:^#|&)token=([A-Za-z0-9]{64})(?:&|$)/.exec(hash);

  return match ? match[1] : null;
}
