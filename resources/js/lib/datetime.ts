/**
 * Date display (Module 33 §50.3: timestamps are stored in UTC and
 * converted at the presentation layer). Every date a page shows goes
 * through here, so it appears in the store's timezone and not in
 * whatever timezone the viewer's browser happens to use.
 *
 * The timezone comes from the server's shared page props and is set once
 * per page visit by app.tsx (see timezoneFromProps).
 */
let displayTimezone: string | undefined;

type TimezoneProps = {
  auth?: { timezone?: string | null } | null;
  storefront?: { store?: { timezone?: string | null } | null } | null;
};

/** A storefront page shows the store's timezone; an admin page the signed-in user's active store's. */
export function timezoneFromProps(pageProps: object): string | undefined {
  const props = pageProps as TimezoneProps;

  return props.storefront?.store?.timezone ?? props.auth?.timezone ?? undefined;
}

export function setDisplayTimezone(timezone: string | undefined): void {
  displayTimezone = timezone;
}

function format(iso: string, options: Intl.DateTimeFormatOptions, timeZone: string | undefined): string {
  const date = new Date(iso);
  try {
    return new Intl.DateTimeFormat(undefined, { ...options, timeZone }).format(date);
  } catch {
    // A timezone this browser does not know: show the date rather than nothing.
    return new Intl.DateTimeFormat(undefined, options).format(date);
  }
}

/** A calendar date, e.g. "10/14/2026". */
export function formatDate(iso: string, timeZone: string | undefined = displayTimezone): string {
  return format(iso, { dateStyle: 'short' }, timeZone);
}

/** Date and time, e.g. "10/14/2026, 9:05 AM". */
export function formatDateTime(iso: string, timeZone: string | undefined = displayTimezone): string {
  return format(iso, { dateStyle: 'short', timeStyle: 'short' }, timeZone);
}
