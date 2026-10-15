/**
 * Phase B54 (Module 34): the help center as the API sends it. What a
 * reader may see is decided on the server (HelpAudiencePolicy): these
 * types only describe the answers.
 */
export type HelpSummary = {
  key: string;
  locale: string;
  title: string;
  summary: string;
  category: string;
  category_label: string;
  type: string;
  type_label: string;
  audience: string;
  status: 'published' | 'deprecated';
  updated_at: string | null;
  applies_from: string;
  current_version: boolean;
};

export type HelpArticle = HelpSummary & {
  html: string;
  revision: number;
  owner: string;
  module: string | null;
  tags: string[];
  error_codes: string[];
  applies_until: string | null;
  deprecated_at: string | null;
  replaced_by: HelpSummary | null;
  translation_outdated: boolean;
  related: HelpSummary[];
};

export type ReleaseNote = {
  version: string;
  title: string;
  summary: string;
  status: string;
  released_on: string | null;
  sections: { key: string; label: string; items: string[] }[];
};

export type HelpHome = {
  categories: { key: string; label: string; count: number }[];
  getting_started: HelpSummary[];
  popular: HelpSummary[];
  latest_release: ReleaseNote | null;
  product_version: string;
  unseen_release: string | null;
};

/** Why a reader found an article not helpful (HelpCatalog::FEEDBACK_REASONS). */
export const FEEDBACK_REASONS: { value: string; label: string }[] = [
  { value: 'missing', label: 'Something is missing' },
  { value: 'outdated', label: 'It is out of date' },
  { value: 'unclear', label: 'It is hard to follow' },
  { value: 'incorrect', label: 'Something is wrong' },
  { value: 'other', label: 'Something else' },
];

export const AUDIENCE_LABELS: Record<string, string> = {
  customer: 'Shoppers',
  merchant: 'Store owners and staff',
  owner: 'Store owners only',
  developer: 'Developers',
  platform: 'Umar Techy staff (internal)',
};

export function articleHref(key: string): string {
  return `/help/articles/${key}`;
}
