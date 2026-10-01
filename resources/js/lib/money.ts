/**
 * Amounts travel in minor units (ADR-003). They are converted with the
 * currency's own number of decimals (JPY has none, KWD has three), never
 * an assumed 2.
 */
export function formatMoney(minor: number, currency: string): string {
  const format = new Intl.NumberFormat(undefined, { style: 'currency', currency });
  const digits = format.resolvedOptions().maximumFractionDigits ?? 2;

  return format.format(minor / 10 ** digits);
}

/** How many decimals a currency has (USD 2, JPY 0, KWD 3). Unknown codes count as 2. */
export function currencyDigits(currency: string): number {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
  } catch {
    return 2;
  }
}

/**
 * What a user typed ("12.50") as minor units (1250), or null when it is
 * not an amount. Done on the digits, not with floating point, so 19.99
 * is 1999 and never 1998. More decimals than the currency has are refused.
 */
export function toMinor(text: string, currency: string): number | null {
  const digits = currencyDigits(currency);
  const match = text.trim().match(/^(\d+)(?:[.,](\d+))?$/);
  if (!match) return null;
  const fraction = match[2] ?? '';
  if (fraction.length > digits) return null;

  return Number(match[1] + fraction.padEnd(digits, '0'));
}

/** Minor units as the plain number a user edits ("12.50"), without a currency sign. */
export function fromMinor(minor: number | null | undefined, currency: string): string {
  if (minor === null || minor === undefined) return '';
  const digits = currencyDigits(currency);
  const text = String(Math.abs(minor)).padStart(digits + 1, '0');
  const whole = digits === 0 ? text : `${text.slice(0, -digits)}.${text.slice(-digits)}`;

  return minor < 0 ? `-${whole}` : whole;
}

/** `formatMoney` that survives a currency code the browser does not know. */
export function money(minor: number | null | undefined, currency: string | null | undefined): string {
  if (minor === null || minor === undefined) return '—';
  try {
    return formatMoney(minor, currency || 'USD');
  } catch {
    return `${fromMinor(minor, 'USD')} ${currency ?? ''}`.trim();
  }
}
