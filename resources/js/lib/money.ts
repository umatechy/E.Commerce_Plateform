/**
 * Amounts travel in minor units (ADR-003). They are converted with the
 * currency's ISO 4217 number of decimals (JPY has none, KWD has three),
 * never an assumed 2 — and never the browser's display rule: browsers
 * show PKR without decimals, but ISO 4217 (and the server, see
 * app/Domain/Settings/Services/Currencies.php) give it 2, so 1999 minor
 * units are Rs. 19.99 everywhere.
 *
 * Owner decision 2026-10-03: Pakistan first. The default currency is PKR,
 * shown as "Rs."; USD, EUR, GBP, AED and SAR are offered too.
 */
export const DEFAULT_CURRENCY = 'PKR';
export const SUPPORTED_CURRENCIES = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'] as const;

export const CURRENCY_NAMES: Record<string, string> = {
  PKR: 'Pakistani rupee (Rs.)',
  USD: 'US dollar ($)',
  EUR: 'Euro (€)',
  GBP: 'British pound (£)',
  AED: 'UAE dirham (AED)',
  SAR: 'Saudi riyal (SAR)',
};

/** ISO 4217 currencies whose minor unit is not hundredths; the same table as the server's. */
const ISO_DIGITS: Record<string, number> = {
  BIF: 0, CLP: 0, DJF: 0, GNF: 0, ISK: 0, JPY: 0, KMF: 0, KRW: 0, PYG: 0, RWF: 0, UGX: 0, UYI: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0,
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
};

/** How many decimals a currency has (PKR and USD 2, JPY 0, KWD 3). Unknown codes count as 2. */
export function currencyDigits(currency: string): number {
  return ISO_DIGITS[currency.toUpperCase()] ?? 2;
}

/** "PKR — Pakistani rupee (Rs.)" for a currency picker. */
export function currencyLabel(code: string): string {
  return CURRENCY_NAMES[code] ? `${code} — ${CURRENCY_NAMES[code]}` : code;
}

/**
 * An amount for people to read. PKR is written the Pakistani way,
 * "Rs. 2,500" — whole rupees without ".00", paisa only when there are
 * some ("Rs. 2,500.50"). Other currencies use the browser's format with
 * their ISO number of decimals ("$17.50", "€1,234.00").
 */
export function formatMoney(minor: number, currency: string): string {
  const code = currency.toUpperCase();
  const digits = currencyDigits(code);
  const value = minor / 10 ** digits;

  if (code === 'PKR') {
    const whole = minor % 10 ** digits === 0;
    const number = new Intl.NumberFormat('en-PK', { minimumFractionDigits: whole ? 0 : digits, maximumFractionDigits: digits }).format(Math.abs(value));

    return `${minor < 0 ? '-' : ''}Rs. ${number}`;
  }

  return new Intl.NumberFormat(undefined, { style: 'currency', currency: code, minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

/**
 * What a user typed ("12.50") as minor units (1250), or null when it is
 * not an amount. Done on the digits, not with floating point, so 19.99
 * is 1999 and never 1998. More decimals than the currency has are refused.
 */
export function toMinor(text: string, currency: string): number | null {
  const digits = currencyDigits(currency);
  const trimmed = text.trim();
  // "2,500" or "1,25,000.50": commas grouping digits are thousands
  // separators; a lone comma before 1–2 digits ("12,50") is a decimal comma.
  const grouped = /^\d{1,3}(,\d{2,3})+(\.\d+)?$/.test(trimmed) && !/^\d{1,3},\d{1,2}$/.test(trimmed);
  const match = (grouped ? trimmed.replace(/,/g, '') : trimmed).match(/^(\d+)(?:[.,](\d+))?$/);
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
  const code = currency || DEFAULT_CURRENCY;
  try {
    return formatMoney(minor, code);
  } catch {
    return `${fromMinor(minor, code)} ${code}`.trim();
  }
}
