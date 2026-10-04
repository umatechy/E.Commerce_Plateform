import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import ImageUploadField from '@/Components/ui/ImageUploadField';
import ProductEdit from '@/Pages/Catalog/ProductEdit';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

beforeEach(() => {
  setPage({ ...owner, currency: 'PKR' }, '/');
  URL.createObjectURL = vi.fn(() => 'blob:preview');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  routerMock.visit.mockReset();
});

function Field({ use = 'path' }: { use?: 'path' | 'url' }) {
  const [value, setValue] = useState('');

  return <ImageUploadField label="Logo" purpose="logo" use={use} value={value} onChange={setValue} />;
}

describe('image upload field', () => {
  it('uploads a chosen PNG and holds the address the server gave', async () => {
    let sent: FormData | null = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /store/media': (_url, init) => {
        sent = init.body as FormData;

        return json(201, { data: { path: '/storage/stores/S/media/a.png', url: 'http://localhost/storage/stores/S/media/a.png' } });
      },
    }));
    render(<Field />);

    const file = new File(['png'], 'logo.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Choose a file for Logo'), { target: { files: [file] } });

    await waitFor(() => expect((screen.getByLabelText(/^Logo/) as HTMLInputElement).value).toBe('/storage/stores/S/media/a.png'));
    expect(sent!.get('purpose')).toBe('logo');
    expect((sent!.get('file') as File).name).toBe('logo.png');
    expect(screen.getByAltText('Logo preview').getAttribute('src')).toBe('/storage/stores/S/media/a.png');
  });

  it('keeps typing an address possible and refuses other file types before sending', async () => {
    const fetchMock = routeFetch({});
    vi.stubGlobal('fetch', fetchMock);
    render(<Field />);

    fireEvent.change(screen.getByLabelText(/^Logo/), { target: { value: 'https://cdn.example.com/logo.png' } });
    expect((screen.getByLabelText(/^Logo/) as HTMLInputElement).value).toBe('https://cdn.example.com/logo.png');
    fireEvent.change(screen.getByLabelText('Choose a file for Logo'), { target: { files: [new File(['gif'], 'a.gif', { type: 'image/gif' })] } });
    expect(await screen.findByText('Choose a JPG or PNG image.')).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('new product with pictures', () => {
  it('creates the product, then uploads the chosen pictures to it', async () => {
    const uploads: string[] = [];
    vi.stubGlobal('fetch', routeFetch({
      '/brands': () => json(200, { data: [] }),
      '/categories': () => json(200, { data: [] }),
      'POST /products': () => json(201, { data: { id: '01JPRODUCT0000000000000001', name: 'Rose' } }),
      'POST /products/01JPRODUCT0000000000000001/images': (_url, init) => {
        uploads.push(((init.body as FormData).get('image') as File).name);

        return json(201, { data: {} });
      },
    }));
    render(<ProductEdit productId={null} />);

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Rose' } });
    fireEvent.change(screen.getByLabelText('Choose files (JPG, PNG)'), { target: { files: [new File(['a'], 'front.jpg', { type: 'image/jpeg' }), new File(['b'], 'back.png', { type: 'image/png' }), new File(['c'], 'x.gif', { type: 'image/gif' })] } });
    expect(screen.getAllByRole('img')).toHaveLength(2); // the GIF is left out
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/products/01JPRODUCT0000000000000001'));
    expect(uploads).toEqual(['front.jpg', 'back.png']);
  });
});
