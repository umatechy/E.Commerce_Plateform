import { describe, expect, it } from 'vitest';
import { currencyDigits, currencyLabel, DEFAULT_CURRENCY, formatMoney, fromMinor, money, SUPPORTED_CURRENCIES, toMinor } from './money';

describe('Pakistan-first currency (owner decision 2026-10-03)', () => {
  it('defaults to PKR and offers five more', () => {
    expect(DEFAULT_CURRENCY).toBe('PKR');
    expect([...SUPPORTED_CURRENCIES]).toEqual(['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR']);
    expect(currencyLabel('PKR')).toBe('PKR — Pakistani rupee (Rs.)');
  });

  it('uses ISO 4217 decimals, not the browser rule that shows PKR without any', () => {
    expect(currencyDigits('PKR')).toBe(2);
    expect(currencyDigits('pkr')).toBe(2);
    expect(currencyDigits('JPY')).toBe(0);
    expect(currencyDigits('KWD')).toBe(3);
    // 1999 minor units are Rs. 19.99, never Rs. 1,999.
    expect(fromMinor(1999, 'PKR')).toBe('19.99');
    expect(toMinor('19.99', 'PKR')).toBe(1999);
  });

  it('writes rupees as "Rs." with paisa only when there are some', () => {
    expect(formatMoney(250000, 'PKR')).toBe('Rs. 2,500');
    expect(formatMoney(250050, 'PKR')).toBe('Rs. 2,500.50');
    expect(formatMoney(-5000, 'PKR')).toBe('-Rs. 50');
    expect(money(150000000, null)).toBe('Rs. 1,500,000');
  });

  it('keeps the other currencies in their own format and decimals', () => {
    expect(formatMoney(1750, 'USD')).toContain('17.50');
    expect(formatMoney(1750, 'USD')).toContain('$');
    expect(formatMoney(123400, 'EUR')).toContain('1,234.00');
  });

  it('reads thousands separators and a decimal comma', () => {
    expect(toMinor('2,500', 'PKR')).toBe(250000);
    expect(toMinor('1,25,000.50', 'PKR')).toBe(12500050);
    expect(toMinor('12,50', 'EUR')).toBe(1250);
    expect(toMinor('12.505', 'PKR')).toBeNull();
    expect(toMinor('abc', 'PKR')).toBeNull();
  });
});
