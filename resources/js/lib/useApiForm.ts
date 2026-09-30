import { useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from './adminApi';

/**
 * A small form helper for pages that post to the JSON API (/api/v1)
 * rather than to an Inertia endpoint: Inertia's useForm expects an
 * Inertia response, so a JSON answer opened its error dialog and 422
 * field errors were never shown. Field errors land under their field,
 * anything else under `form`.
 */
type FormErrors<T> = Partial<Record<keyof T | 'form', string>>;

export function useApiForm<T extends Record<string, string>>(initial: T) {
  const [data, setAll] = useState<T>(initial);
  const [errors, setErrors] = useState<FormErrors<T>>({});
  const [processing, setProcessing] = useState(false);

  function setData(field: keyof T, value: string) {
    setAll((current) => ({ ...current, [field]: value }));
  }

  async function post(path: string, onSuccess: () => void) {
    setProcessing(true);
    setErrors({});
    try {
      await adminFetch(path, { method: 'POST', body: data });
      onSuccess(); // stays "processing" while the next page loads
    } catch (error) {
      const fields = error instanceof AdminApiError && error.status === 422 ? (error.body.errors as Record<string, string[]> | undefined) : undefined;
      setErrors((fields ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) }) as FormErrors<T>);
      setProcessing(false);
    }
  }

  return { data, setData, post, processing, errors };
}
