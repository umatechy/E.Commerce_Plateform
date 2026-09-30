import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import HealthCheckList, { StatusBadge } from './HealthCheckList';

afterEach(cleanup);

describe('HealthCheckList', () => {
  it('shows every check with its title, message and server-computed status', () => {
    render(
      <HealthCheckList
        checks={[
          { key: 'subscription', status: 'ok', message: 'The subscription is active.' },
          { key: 'backups', status: 'warning', message: 'No verified backup covers this store yet.' },
          { key: 'custom_check', status: 'critical', message: 'Something is wrong.' },
        ]}
      />,
    );

    expect(screen.getByText('Subscription')).toBeTruthy();
    expect(screen.getByText('No verified backup covers this store yet.')).toBeTruthy();
    expect(screen.getByText('Needs attention')).toBeTruthy();
    expect(screen.getByText('custom_check')).toBeTruthy(); // unknown keys fall back to the raw key
    expect(screen.getByText('Critical')).toBeTruthy();
  });

  it('labels each status', () => {
    render(<StatusBadge status="ok" />);

    expect(screen.getByText('OK')).toBeTruthy();
  });
});
