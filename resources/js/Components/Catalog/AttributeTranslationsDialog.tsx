import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { TextField } from '@/Components/ui/Form';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import type { Attribute } from '@/lib/catalog';

/**
 * Phase B42 (Module 07 §38): an attribute's name and all its values in the
 * store's other storefront languages, saved per language in one request. A
 * field left empty shows the original. Filter addresses keep the original
 * slugs, so they work in every language.
 */
type Data = {
  languages: { code: string; name: string; native: string; dir: string }[];
  original: { name: string; values: { id: number; value: string; is_active: boolean }[] };
  translations: Record<string, { name?: string; values?: Record<string, string> }>;
};

export default function AttributeTranslationsDialog({ attribute, canEdit, onClose }: { attribute: Attribute; canEdit: boolean; onClose: () => void }) {
  const state = useApi<{ data: Data }>(`/attributes/${attribute.id}/translations`);
  const data = state.data?.data ?? null;
  const [draft, setDraft] = useState<Record<string, { name: string; values: Record<string, string> }>>({});
  const { busy, run } = useAction();

  useEffect(() => {
    if (data) {
      setDraft(Object.fromEntries(data.languages.map((l) => [l.code, { name: data.translations[l.code]?.name ?? '', values: { ...(data.translations[l.code]?.values ?? {}) } }])));
    }
  }, [data]);

  return (
    <Dialog open wide title={`Translations — ${attribute.name}`} onClose={onClose} busy={busy !== null}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : data === null ? (
        <Skeleton lines={4} />
      ) : data.languages.length === 0 ? (
        <p className="text-sm text-slate-600">
          Your storefront has one language. To show attributes in Urdu as well, add <code>ur</code> to Storefront languages in <Link href="/settings" className="text-indigo-700 underline">Settings</Link>.
        </p>
      ) : (
        <div className="space-y-6">
          {data.languages.map((language) => (
            <section key={language.code} aria-label={language.name} className="space-y-3">
              <h3 className="text-sm font-semibold text-slate-900">
                {language.name} <span lang={language.code}>({language.native})</span>
              </h3>
              <div dir={language.dir} lang={language.code} className="grid gap-3 sm:grid-cols-2">
                <TextField
                  label="Name"
                  optional
                  disabled={!canEdit}
                  hint={`Original: ${data.original.name}`}
                  value={draft[language.code]?.name ?? ''}
                  maxLength={255}
                  onChange={(v) => setDraft((d) => ({ ...d, [language.code]: { ...d[language.code], name: v } }))}
                />
                {data.original.values.map((value) => (
                  <TextField
                    key={value.id}
                    label={value.value}
                    optional
                    disabled={!canEdit}
                    value={draft[language.code]?.values[String(value.id)] ?? ''}
                    maxLength={255}
                    hint={value.is_active ? undefined : 'Not offered now'}
                    onChange={(v) => setDraft((d) => ({ ...d, [language.code]: { ...d[language.code], values: { ...d[language.code].values, [String(value.id)]: v } } }))}
                  />
                ))}
              </div>
              {canEdit && (
                <Button
                  variant="primary"
                  busy={busy === language.code}
                  busyLabel="Saving…"
                  onClick={() =>
                    run(language.code, () => adminFetch<{ data: Data }>(`/attributes/${attribute.id}/translations`, {
                      method: 'PUT',
                      body: { locale: language.code, name: draft[language.code]?.name || null, values: Object.fromEntries(data.original.values.map((v) => [String(v.id), draft[language.code]?.values[String(v.id)] || null])) },
                    }), { success: `${language.name} saved.` }).then((saved) => saved && state.setData(saved))
                  }
                >
                  Save {language.name}
                </Button>
              )}
            </section>
          ))}
          <div className="flex justify-end"><Button onClick={onClose}>Close</Button></div>
        </div>
      )}
    </Dialog>
  );
}
