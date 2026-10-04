/**
 * Module 17 §8 "Typography" (Phase B36): the theme's fonts, served from this
 * site (npm @fontsource packages bundled by Vite) — no request goes to a
 * third-party font service ("privacy-aware"). Only the fonts a page uses are
 * loaded, Latin subset, the weights the storefront uses; font-display: swap
 * comes with the files, so text shows at once in a fallback font.
 *
 * The list matches App\Domain\Theme\Support\ThemeOptions::FONTS. system-ui
 * and Georgia are on every device and need no file.
 */
const LOADERS: Record<string, () => Promise<unknown>[]> = {
  Inter: () => [import('@fontsource/inter/latin-400.css'), import('@fontsource/inter/latin-600.css'), import('@fontsource/inter/latin-700.css')],
  Roboto: () => [import('@fontsource/roboto/latin-400.css'), import('@fontsource/roboto/latin-500.css'), import('@fontsource/roboto/latin-700.css')],
  Poppins: () => [import('@fontsource/poppins/latin-400.css'), import('@fontsource/poppins/latin-600.css'), import('@fontsource/poppins/latin-700.css')],
  Montserrat: () => [import('@fontsource/montserrat/latin-400.css'), import('@fontsource/montserrat/latin-600.css'), import('@fontsource/montserrat/latin-800.css')],
  'DM Sans': () => [import('@fontsource/dm-sans/latin-400.css'), import('@fontsource/dm-sans/latin-500.css'), import('@fontsource/dm-sans/latin-700.css')],
  'Playfair Display': () => [import('@fontsource/playfair-display/latin-400.css'), import('@fontsource/playfair-display/latin-600.css'), import('@fontsource/playfair-display/latin-700.css')],
  Merriweather: () => [import('@fontsource/merriweather/latin-400.css'), import('@fontsource/merriweather/latin-700.css')],
  Lora: () => [import('@fontsource/lora/latin-400.css'), import('@fontsource/lora/latin-600.css')],
  // Phase B38: Urdu (Nastaliq), loaded on Urdu pages only.
  'Noto Nastaliq Urdu': () => [import('@fontsource/noto-nastaliq-urdu/arabic-400.css'), import('@fontsource/noto-nastaliq-urdu/arabic-700.css')],
};

const FALLBACK: Record<string, string> = {
  Georgia: 'Georgia, "Times New Roman", serif',
  'Playfair Display': '"Playfair Display", Georgia, serif',
  Merriweather: 'Merriweather, Georgia, serif',
  Lora: 'Lora, Georgia, serif',
};

const loaded = new Set<string>();

/** Starts loading the font's files once per page; never throws (the fallback font stays). */
export function loadFont(name: string | undefined): void {
  if (!name || loaded.has(name) || !LOADERS[name]) return;
  loaded.add(name);
  Promise.all(LOADERS[name]()).catch(() => {
    loaded.delete(name);
  });
}

/** The CSS font-family stack for a theme font. */
export function fontStack(name: string | undefined): string {
  if (!name || name === 'system-ui') return 'system-ui, -apple-system, "Segoe UI", sans-serif';

  return FALLBACK[name] ?? `"${name}", system-ui, sans-serif`;
}
