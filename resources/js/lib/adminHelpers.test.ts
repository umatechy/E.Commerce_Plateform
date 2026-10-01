import { afterEach, describe, expect, it } from 'vitest';
import { readPage } from './useApi';
import { readUrlState, toSearch } from './useUrlState';
import { currencyDigits, fromMinor, toMinor } from './money';
import { setDisplayTimezone, toStoreLocalInput } from './datetime';
import { categoryTree, parseOptionLines, variantLabel, type Category } from './catalog';
import { addressLines, orderCustomer } from './orders';

describe('readPage', () => {
  it('reads a resource collection with meta', () => {
    const page = readPage<{ id: number }>({ data: [{ id: 1 }], meta: { current_page: 2, last_page: 5, total: 110, per_page: 25 } });

    expect(page.rows).toEqual([{ id: 1 }]);
    expect(page.meta).toEqual({ page: 2, lastPage: 5, total: 110, perPage: 25 });
  });

  it('reads a paginator placed inside data', () => {
    const page = readPage<{ id: number }>({ data: { data: [{ id: 7 }], current_page: 1, last_page: 1, total: 1, per_page: 50 } });

    expect(page.rows).toEqual([{ id: 7 }]);
    expect(page.meta?.total).toBe(1);
  });

  it('reads a plain list and survives an unexpected body', () => {
    expect(readPage({ data: [1, 2] })).toEqual({ rows: [1, 2], meta: null });
    expect(readPage(null)).toEqual({ rows: [], meta: null });
    expect(readPage({ data: 'nope' })).toEqual({ rows: [], meta: null });
  });
});

describe('URL filter state', () => {
  const defaults = { search: '', status: '', page: '1' };

  it('reads the filters it knows from the address and ignores the rest', () => {
    expect(readUrlState(defaults, '?search=shirt&page=3&evil=1')).toEqual({ search: 'shirt', status: '', page: '3' });
  });

  it('writes only what differs from the defaults', () => {
    expect(toSearch({ search: 'shirt', status: '', page: '1' }, defaults)).toBe('?search=shirt');
    expect(toSearch(defaults, defaults)).toBe('');
  });

  it('keeps the parameters of another filter group on the same page', () => {
    expect(toSearch({ status: 'open', page: '1' }, { status: '', page: '1' }, '?tab=invoices&status=paid')).toBe('?tab=invoices&status=open');
    expect(toSearch({ status: '', page: '1' }, { status: '', page: '1' }, '?tab=invoices&status=paid')).toBe('?tab=invoices');
  });
});

describe('money entry', () => {
  it('uses the decimals of the currency, never an assumed two', () => {
    expect(currencyDigits('USD')).toBe(2);
    expect(currencyDigits('JPY')).toBe(0);
    expect(currencyDigits('KWD')).toBe(3);
  });

  it('turns typed amounts into exact minor units', () => {
    expect(toMinor('19.99', 'USD')).toBe(1999); // not 1998, as floating point would give
    expect(toMinor('12', 'USD')).toBe(1200);
    expect(toMinor('12,5', 'USD')).toBe(1250);
    expect(toMinor('1500', 'JPY')).toBe(1500);
    expect(toMinor('1.234', 'KWD')).toBe(1234);
  });

  it('refuses what is not an amount in that currency', () => {
    expect(toMinor('12.345', 'USD')).toBeNull(); // more decimals than the currency has
    expect(toMinor('1.5', 'JPY')).toBeNull();
    expect(toMinor('-5', 'USD')).toBeNull();
    expect(toMinor('abc', 'USD')).toBeNull();
    expect(toMinor('', 'USD')).toBeNull();
  });

  it('shows minor units as the number a person edits', () => {
    expect(fromMinor(1999, 'USD')).toBe('19.99');
    expect(fromMinor(5, 'USD')).toBe('0.05');
    expect(fromMinor(1500, 'JPY')).toBe('1500');
    expect(fromMinor(null, 'USD')).toBe('');
    expect(toMinor(fromMinor(123456, 'USD'), 'USD')).toBe(123456);
  });
});

describe('store-local date input', () => {
  afterEach(() => setDisplayTimezone(undefined));

  it('shows a stored UTC time as the wall-clock time of the store', () => {
    // 20:30 UTC on 30 September is 01:30 on 1 October in Karachi (UTC+5).
    expect(toStoreLocalInput('2026-09-30T20:30:00Z', 'Asia/Karachi')).toBe('2026-10-01T01:30');
    expect(toStoreLocalInput('2026-09-30T20:30:00Z', 'UTC')).toBe('2026-09-30T20:30');
  });

  it('uses the page timezone, not the browser one, and handles nothing', () => {
    setDisplayTimezone('America/New_York');

    expect(toStoreLocalInput('2026-01-15T12:00:00Z')).toBe('2026-01-15T07:00');
    expect(toStoreLocalInput(null)).toBe('');
    expect(toStoreLocalInput('not a date')).toBe('');
  });
});

describe('catalog helpers', () => {
  const category = (id: number, parent: number | null, name: string): Category => ({ id, parent_id: parent, name, slug: name, description: null, status: 'active', visibility: 'public', sort_order: 0 });

  it('orders categories as a tree with depths', () => {
    const tree = categoryTree([category(3, 2, 'Shirts'), category(1, null, 'Men'), category(2, 1, 'Tops'), category(4, null, 'Women')]);

    expect(tree.map(({ category: c, depth }) => `${depth}:${c.name}`)).toEqual(['0:Men', '1:Tops', '2:Shirts', '0:Women']);
  });

  it('treats a category whose parent is gone as a top-level one, and loses none in a circle', () => {
    expect(categoryTree([category(5, 99, 'Orphan')])).toEqual([{ category: category(5, 99, 'Orphan'), depth: 0 }]);
    // A circle has no root: both are still listed, and the walk ends.
    expect(categoryTree([category(1, 2, 'A'), category(2, 1, 'B')]).map(({ category: c }) => c.name)).toEqual(['A', 'B']);
  });

  it('reads variant options from lines and reports a line it cannot read', () => {
    expect(parseOptionLines('Size: M\n Colour : Red \n')).toEqual({ values: { Size: 'M', Colour: 'Red' } });
    expect(parseOptionLines('Size M').error).toMatch(/Name: value/);
    expect(parseOptionLines('').values).toEqual({});
  });

  it('names a variant by its options, or its SKU', () => {
    expect(variantLabel({ sku: 'X-1', option_values: { Size: 'M', Colour: 'Red' } })).toBe('Size: M, Colour: Red');
    expect(variantLabel({ sku: 'X-1', option_values: null })).toBe('X-1');
  });
});

describe('order helpers', () => {
  it('names who placed an order', () => {
    expect(orderCustomer({ is_guest_order: true, guest_name: 'Ayesha', customer: null })).toBe('Ayesha (guest)');
    expect(orderCustomer({ is_guest_order: false, customer: { id: '1', name: 'Bilal', email: 'b@example.com' } })).toBe('Bilal');
  });

  it('shows an address snapshot as lines, without empty or nested values', () => {
    expect(addressLines({ line1: '12 Mall Road', line2: '', city: 'Lahore', meta: { a: 1 }, postal_code: 54000 })).toEqual(['12 Mall Road', 'Lahore', '54000']);
    expect(addressLines(null)).toEqual([]);
  });
});
