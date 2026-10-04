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
// Phase B38 (LOC-004): a storefront formats dates in its language (en-PK, ur-PK); admin pages use the browser's.
let displayLocale: string | undefined;

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

/** The language tag of a storefront page (e.g. ur-PK), or undefined on admin pages. */
export function localeFromProps(pageProps: object): string | undefined {
  const props = pageProps as { storefront?: { language?: { current?: string; offered?: { code: string; tag: string }[] } } };
  const language = props.storefront?.language;

  return language?.offered?.find((item) => item.code === language.current)?.tag;
}

export function setDisplayLocale(locale: string | undefined): void {
  displayLocale = locale;
}

function format(iso: string, options: Intl.DateTimeFormatOptions, timeZone: string | undefined): string {
  const date = new Date(iso);
  try {
    return new Intl.DateTimeFormat(displayLocale, { ...options, timeZone }).format(date);
  } catch {
    // A timezone this browser does not know: show the date rather than nothing.
    return new Intl.DateTimeFormat(displayLocale, options).format(date);
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

/** The timezone admin pages show dates in (the store's), for labels such as "Times are in Asia/Karachi". */
export function displayTimezoneName(): string {
  return displayTimezone ?? 'UTC';
}

/**
 * A stored UTC instant as the value of an <input type="datetime-local">
 * in the store's timezone ("2026-10-14T09:05"). The server reads such an
 * offset-less value back as store-local time (StoreClock, Phase B28), so
 * a date is edited in the same timezone it is shown in, whatever the
 * browser's own timezone is.
 */
export function toStoreLocalInput(iso: string | null | undefined, timeZone: string | undefined = displayTimezone): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const parts = (zone: string | undefined) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
  let list: Intl.DateTimeFormatPart[];
  try {
    list = parts(timeZone);
  } catch {
    list = parts('UTC');
  }
  const get = (type: string) => list.find((part) => part.type === type)?.value ?? '00';

  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** Platform billing runs on UTC periods (Module 33 §50.5): these dates are shown as UTC dates, never moved into the store's timezone. */
export function formatUtcDate(iso: string | null | undefined): string {
  return iso ? formatDate(iso, 'UTC') : '—';
}

/** A date and time, or a dash when there is none. */
export function dateTimeOrDash(iso: string | null | undefined): string {
  return iso ? formatDateTime(iso) : '—';
}
