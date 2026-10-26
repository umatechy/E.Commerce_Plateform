import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import CodeField from '@/Components/Auth/CodeField';

/** The two-step code lives 30 seconds in the authenticator app: the sixth digit sends it. */
afterEach(cleanup);

function Field({ onComplete }: { onComplete: (code: string) => void }) {
  const [value, setValue] = useState('');

  return <CodeField id="code" value={value} onChange={setValue} onComplete={onComplete} />;
}

describe('the six-digit code', () => {
  it('is sent when the sixth digit is typed, once, without pressing a button', () => {
    const sent = vi.fn();
    render(<Field onComplete={sent} />);
    const input = screen.getByRole('textbox');

    for (const partial of ['1', '12', '123', '1234', '12345']) fireEvent.change(input, { target: { value: partial } });
    expect(sent).not.toHaveBeenCalled();

    fireEvent.change(input, { target: { value: '123456' } });
    expect(sent).toHaveBeenCalledTimes(1);
    expect(sent).toHaveBeenCalledWith('123456');

    // Typing more into a full field sends nothing again.
    fireEvent.change(input, { target: { value: '1234567' } });
    expect(sent).toHaveBeenCalledTimes(1);
  });

  it('ignores letters and spaces while counting digits', () => {
    const sent = vi.fn();
    render(<Field onComplete={sent} />);

    fireEvent.change(screen.getByRole('textbox'), { target: { value: '12 34 5a6' } });
    expect(sent).toHaveBeenCalledWith('123456');
  });
});
