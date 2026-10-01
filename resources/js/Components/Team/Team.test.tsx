import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import MemberList from './MemberList';
import { seatsFull, seatText, tokenFromHash, type TeamMember, type TeamRole } from '@/lib/team';

afterEach(cleanup);

const roles: TeamRole[] = [
  { slug: 'owner', name: 'Owner', is_system: true, grantable: false },
  { slug: 'administrator', name: 'Administrator', is_system: true, grantable: false },
  { slug: 'staff', name: 'Staff', is_system: true, grantable: true },
  { slug: 'order-manager', name: 'Order Manager', is_system: true, grantable: true },
];

const member = (overrides: Partial<TeamMember> = {}): TeamMember => ({
  id: '01JTEAMMEMBER0000000000001',
  name: 'Sara Khan',
  email: 'sara@example.com',
  role: { slug: 'staff', name: 'Staff' },
  status: 'active',
  is_owner: false,
  is_you: false,
  can_manage: true,
  joined_at: null,
  status_changed_at: null,
  ...overrides,
});

describe('team helpers', () => {
  it('describes seats with and without a package limit', () => {
    expect(seatText({ used: 3, limit: 5 })).toBe('3 of 5 seats used');
    expect(seatText({ used: 1, limit: null })).toBe('1 team member');
    expect(seatsFull({ used: 5, limit: 5 })).toBe(true);
    expect(seatsFull({ used: 9, limit: null })).toBe(false);
  });

  it('reads only a well-formed token from the fragment', () => {
    const token = 'a'.repeat(64);
    expect(tokenFromHash(`#token=${token}`)).toBe(token);
    expect(tokenFromHash('#token=short')).toBeNull();
    expect(tokenFromHash('')).toBeNull();
  });
});

describe('MemberList', () => {
  it('offers only grantable roles and the actions the server allows', () => {
    const onAction = vi.fn();
    render(<MemberList members={[member()]} roles={roles} canManage busy={null} onRoleChange={vi.fn()} onAction={onAction} />);

    const options = Array.from(screen.getByLabelText('Role for Sara Khan').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['Staff', 'Order Manager']);
    fireEvent.click(screen.getByText('Suspend'));
    expect(onAction).toHaveBeenCalledWith(expect.objectContaining({ id: '01JTEAMMEMBER0000000000001' }), 'suspend');
  });

  it('shows no controls for the owner, yourself or without the manage ability', () => {
    render(
      <MemberList
        members={[member({ id: 'o', name: 'Owner Person', is_owner: true, can_manage: false, role: { slug: 'owner', name: 'Owner' } }), member({ id: 'y', name: 'You', is_you: true, can_manage: false })]}
        roles={roles}
        canManage
        busy={null}
        onRoleChange={vi.fn()}
        onAction={vi.fn()}
      />,
    );
    expect(screen.queryByText('Suspend')).toBeNull();
    expect(screen.queryByLabelText('Role for Owner Person')).toBeNull();

    cleanup();
    render(<MemberList members={[member()]} roles={roles} canManage={false} busy={null} onRoleChange={vi.fn()} onAction={vi.fn()} />);
    expect(screen.queryByText('Remove')).toBeNull();
  });

  it('keeps showing a role the viewer cannot give without offering it as a choice', () => {
    render(<MemberList members={[member({ role: { slug: 'custom-x', name: 'Packer' } })]} roles={roles} canManage busy={null} onRoleChange={vi.fn()} onAction={vi.fn()} />);
    const select = screen.getByLabelText('Role for Sara Khan') as HTMLSelectElement;
    expect(select.value).toBe('custom-x');
  });
});
