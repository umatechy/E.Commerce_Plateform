import { adminFetch } from './adminApi';

/**
 * UI-20 — the admin theme colour and language follow the user to every
 * device: a change is saved with the account (PUT /api/v1/auth/preferences)
 * as well as on this browser. Saving is best effort; the change already
 * applies here even when the request fails (for example signed out).
 */
export type AdminPreferences = { theme?: string; language?: string; mode?: string; saved_views?: Record<string, { name: string; query: string }[]> };

export function savePreference(preference: AdminPreferences): void {
  adminFetch('/auth/preferences', { method: 'PUT', body: preference }).catch(() => undefined);
}
