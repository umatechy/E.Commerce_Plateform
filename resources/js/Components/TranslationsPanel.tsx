import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import Button from '@/Components/ui/Button';
import { TextAreaField, TextField } from '@/Components/ui/Form';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Phase B38 (Module 06 §101, Module 07 §99): a product's, category's or
 * brand's name and description in the store's other storefront languages.
 * A field left empty shows the original on the storefront. Saved per
 * language through PUT /api/v1/translations/{type}/{id}.
 */
type Data = {
  default_locale: string;
  languages: { code: string; name: string; native: string; dir: string }[];
  fields: string[];
  original: Record<string, string | null>;
  translations: Record<string, Record<string, string>>;
};

const FIELD_LABEL: Record<string, string> = { name: 'Name', short_description: 'Short description', description: 'Description' };

export default function TranslationsPanel({ type, id, canEdit }: { type: 'product' | 'category' | 'brand'; id: string | number; canEdit: boolean }) {
  const state = useApi<{ data: Data }>(`/translations/${type}/${id}`);
  const data = state.data?.data ?? null;
  const [values, setValues] = useState<Record<string, Record<string, string>>>({});
  const { busy, run } = useAction();

  useEffect(() => {
    if (data) setValues(Object.fromEntries(data.languages.map((language) => [language.code, { ...(data.translations[language.code] ?? {}) }])));
  }, [data]);

  if (state.error) return <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (data === null) return <Skeleton lines={3} />;
  if (data.languages.length === 0) {
    return (
      <p className="text-sm text-slate-600">
        Your storefront has one language. To show this in Urdu as well, add <code>ur</code> to Storefront languages in <Link href="/settings" className="text-indigo-700 underline">Settings</Link>.
      </p>
    );
  }

  return (
    <div className="space-y-6">
      {data.languages.map((language) => (
        <section key={language.code} aria-label={language.name} className="space-y-3">
          <h3 className="text-sm font-semibold text-slate-900">
            {language.name} <span lang={language.code}>({language.native})</span>
          </h3>
          {data.fields.map((field) => {
            const common = {
              label: FIELD_LABEL[field] ?? field,
              optional: true,
              disabled: !canEdit,
              value: values[language.code]?.[field] ?? '',
              onChange: (value: string) => setValues((current) => ({ ...current, [language.code]: { ...(current[language.code] ?? {}), [field]: value } })),
              hint: data.original[field] ? `Original: ${String(data.original[field]).slice(0, 120)}` : undefined,
            };

            return field === 'name' ? (
              <TextField key={field} {...common} maxLength={255} dir={language.dir as 'rtl' | 'ltr'} lang={language.code} />
            ) : (
              <TextAreaField key={field} {...common} rows={field === 'description' ? 5 : 2} dir={language.dir as 'rtl' | 'ltr'} lang={language.code} />
            );
          })}
          {canEdit && (
            <Button
              size="sm"
              busy={busy === language.code}
              busyLabel="Saving…"
              onClick={() =>
                void run(language.code, () => adminFetch<{ data: Data }>(`/translations/${type}/${id}`, { method: 'PUT', body: { locale: language.code, fields: values[language.code] ?? {} } }), {
                  success: `${language.name} saved.`,
                }).then((result) => result && state.setData(result))
              }
            >
              Save {language.name}
            </Button>
          )}
        </section>
      ))}
    </div>
  );
}
