import { useCallback, useEffect, useRef, useState } from 'react';
import { adminErrorMessage, adminFetch, AdminApiError } from './adminApi';

/**
 * Loads one API resource for a page. Every page that reads data uses
 * this, so they all behave the same: a request that does not answer ends
 * in an error with "Try again" (never an endless "Loading…"), and an
 * answer that arrives after a newer request is ignored.
 */
type Query = Record<string, string | number | boolean | undefined | null>;

export type ApiState<T> = {
  data: T | null;
  loading: boolean;
  error: string | null;
  /** HTTP status of the failure (0: no answer). Lets a page tell "not allowed" from "broken". */
  errorStatus: number | null;
  errorCode: string | null;
  reload: () => void;
  setData: (data: T | null) => void;
};

export function useApi<T>(path: string | null, query?: Query): ApiState<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(path !== null);
  const [error, setError] = useState<{ message: string; status: number; code: string | null } | null>(null);
  const [version, setVersion] = useState(0);
  const latest = useRef(0);
  const key = path === null ? null : `${path}?${JSON.stringify(query ?? {})}`;

  useEffect(() => {
    if (key === null || path === null) {
      setLoading(false);

      return;
    }
    const request = ++latest.current;
    setLoading(true);
    setError(null);

    adminFetch<T>(path, { query })
      .then((body) => {
        if (request !== latest.current) return;
        setData(body);
        setLoading(false);
      })
      .catch((e: unknown) => {
        if (request !== latest.current) return;
        setError({
          message: adminErrorMessage(e, 'Could not load this page. Please try again.'),
          status: e instanceof AdminApiError ? e.status : 0,
          code: e instanceof AdminApiError ? (e.code ?? null) : null,
        });
        setLoading(false);
      });
    // `key` stands for path + query; the objects themselves change identity every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, version]);

  const reload = useCallback(() => setVersion((v) => v + 1), []);

  return { data, loading, error: error?.message ?? null, errorStatus: error?.status ?? null, errorCode: error?.code ?? null, reload, setData };
}

export type PageMeta = { page: number; lastPage: number; total: number; perPage: number };

/**
 * The API pages lists in two shapes: a resource collection
 * (`{data: [], meta: {...}}`) and a paginator inside `data`
 * (`{data: {data: [], current_page, ...}}`). A few lists are not paged at
 * all (`{data: []}`). This reads all three.
 */
export function readPage<T>(body: unknown): { rows: T[]; meta: PageMeta | null } {
  const root = (body ?? {}) as Record<string, unknown>;
  const inner = root.data;

  if (Array.isArray(inner)) {
    const meta = root.meta as Record<string, unknown> | undefined;

    return { rows: inner as T[], meta: meta && typeof meta.current_page === 'number' ? toMeta(meta) : null };
  }
  if (inner && typeof inner === 'object' && Array.isArray((inner as Record<string, unknown>).data)) {
    const paginator = inner as Record<string, unknown>;

    return { rows: paginator.data as T[], meta: typeof paginator.current_page === 'number' ? toMeta(paginator) : null };
  }

  return { rows: [], meta: null };
}

function toMeta(source: Record<string, unknown>): PageMeta {
  return {
    page: Number(source.current_page ?? 1),
    lastPage: Number(source.last_page ?? 1),
    total: Number(source.total ?? 0),
    perPage: Number(source.per_page ?? 25),
  };
}

/** A paged list: `rows` is null until the first answer. */
export function usePagedApi<T>(path: string | null, query?: Query) {
  const state = useApi<unknown>(path, query);
  const page = state.data === null ? null : readPage<T>(state.data);

  return { ...state, rows: page?.rows ?? null, meta: page?.meta ?? null };
}
