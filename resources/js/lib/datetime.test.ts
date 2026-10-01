import { afterEach, describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, setDisplayTimezone, timezoneFromProps } from './datetime';

// 20:30 UTC on 30 September is already 1 October in Karachi (UTC+5).
const late = '2026-09-30T20:30:00Z';
const day = (iso: string, timeZone?: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeZone }).format(new Date(iso));

describe('datetime', () => {
  afterEach(() => setDisplayTimezone(undefined));

  it('shows a date in the timezone it is given', () => {
    expect(formatDate(late, 'UTC')).toBe(day(late, 'UTC'));
    expect(formatDate(late, 'Asia/Karachi')).toBe(day(late, 'Asia/Karachi'));
    expect(formatDate(late, 'Asia/Karachi')).not.toBe(formatDate(late, 'UTC'));
  });

  it('uses the page timezone when none is given', () => {
    setDisplayTimezone('Asia/Karachi');

    expect(formatDate(late)).toBe(day(late, 'Asia/Karachi'));
    expect(formatDateTime(late)).toContain(day(late, 'Asia/Karachi'));
  });

  it('still shows a date for a timezone the browser does not know', () => {
    expect(formatDate(late, 'Not/AZone')).toBe(day(late));
  });

  it('takes the storefront timezone before the signed-in user store', () => {
    expect(timezoneFromProps({ auth: { timezone: 'UTC' }, storefront: { store: { timezone: 'Asia/Karachi' } } })).toBe('Asia/Karachi');
    expect(timezoneFromProps({ auth: { timezone: 'Europe/London' } })).toBe('Europe/London');
    expect(timezoneFromProps({})).toBeUndefined();
  });
});
