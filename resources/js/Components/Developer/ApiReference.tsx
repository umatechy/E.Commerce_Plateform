import { useEffect, useState } from 'react';
import Badge from '@/Components/ui/Badge';
import { Card, Skeleton } from '@/Components/ui/Page';

/**
 * Phase B53 (SRS API-012; Module 31 §48, §51) — the developer API's own
 * description (OpenAPI 3.1, generated from the routes), read by the store's
 * Developer page: the endpoints with their scope, the webhook events, and a
 * link to the full file for tools such as Postman or code generators.
 */
export const SPEC_URL = '/api/dev/v1/openapi.json';

type Operation = { summary: string; description?: string; 'x-scope'?: string };
type Spec = {
  info: { version: string };
  paths: Record<string, Record<string, Operation>>;
  webhooks: Record<string, { post: { description: string } }>;
};

let cached: Promise<Spec> | null = null;

/** The spec, fetched once per page load. */
export function useDeveloperSpec(): { spec: Spec | null; error: boolean } {
  const [spec, setSpec] = useState<Spec | null>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    cached ??= fetch(SPEC_URL, { headers: { Accept: 'application/json' } }).then((r) => {
      if (!r.ok) throw new Error(String(r.status));

      return r.json() as Promise<Spec>;
    });
    let alive = true;
    cached.then((s) => alive && setSpec(s)).catch(() => {
      cached = null;
      if (alive) setError(true);
    });

    return () => {
      alive = false;
    };
  }, []);

  return { spec, error };
}

/** "order.created" → its one-line description from the spec. */
export function webhookEvents(spec: Spec): { event: string; description: string }[] {
  return Object.entries(spec.webhooks).map(([event, w]) => ({ event, description: w.post.description.split('\n')[0] }));
}

export default function ApiReference() {
  const { spec, error } = useDeveloperSpec();

  return (
    <Card
      title="API reference"
      description="Read-only, one store per key. Errors always carry a code, a message and a request ID; lists are paged (per_page up to 100)."
      actions={<a href={SPEC_URL} target="_blank" rel="noreferrer" className="text-sm font-medium text-brand-700 underline">OpenAPI file<span className="sr-only"> (opens in a new tab)</span></a>}
    >
      {error && <p className="text-sm text-amber-800">The reference could not be loaded. The OpenAPI file link still works.</p>}
      {!spec && !error && <Skeleton lines={4} />}
      {spec && (
        <div className="space-y-4">
          <ul className="divide-y divide-slate-100">
            {Object.entries(spec.paths).flatMap(([path, ops]) => Object.entries(ops).map(([method, op]) => (
              <li key={`${method} ${path}`} className="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
                <span className="min-w-0">
                  <code className="mr-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold uppercase">{method}</code>
                  <code className="break-all">/api/dev/v1{path}</code>
                  <span className="ml-2 text-slate-600">{op.summary}</span>
                </span>
                {op['x-scope'] && <Badge>{op['x-scope']}</Badge>}
              </li>
            )))}
          </ul>
          <details className="rounded-md border border-slate-200 p-3 text-sm">
            <summary className="cursor-pointer font-medium text-slate-800">Webhook events ({Object.keys(spec.webhooks).length})</summary>
            <ul className="mt-2 space-y-1">
              {webhookEvents(spec).map((w) => <li key={w.event}><code className="text-xs">{w.event}</code> <span className="text-slate-600">— {w.description}</span></li>)}
            </ul>
            <p className="mt-2 text-xs text-slate-500">Each delivery is signed: X-Umartechy-Signature is the HMAC-SHA256 of the raw body with the webhook’s signing secret. Failed deliveries are retried after 10 s, 30 s, 1 min, 5 min and 15 min.</p>
          </details>
        </div>
      )}
    </Card>
  );
}
