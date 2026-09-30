import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import EmptyState from './EmptyState';
import ErrorState from './ErrorState';

afterEach(cleanup);

describe('EmptyState', () => {
  it('renders the title and the optional description', () => {
    render(<EmptyState title="No orders yet" description="Orders appear here once customers check out." />);

    expect(screen.getByText('No orders yet')).toBeTruthy();
    expect(screen.getByText('Orders appear here once customers check out.')).toBeTruthy();
  });

  it('omits the description paragraph when none is given', () => {
    const { container } = render(<EmptyState title="Nothing here" />);

    expect(container.querySelectorAll('p')).toHaveLength(1);
  });
});

describe('ErrorState', () => {
  it('shows the error message', () => {
    render(<ErrorState message="Could not load inventory." />);

    expect(screen.getByText('Could not load inventory.')).toBeTruthy();
  });
});
