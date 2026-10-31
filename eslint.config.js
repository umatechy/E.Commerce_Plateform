import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

// ESLint 9 flat config. Only the frontend source is linted; build output,
// dependencies and the PHP side are out of scope.
export default tseslint.config(
  { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/cache/**'] },
  {
    files: ['resources/js/**/*.{ts,tsx}'],
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    languageOptions: {
      ecmaVersion: 2022,
      globals: globals.browser,
    },
    plugins: { 'react-hooks': reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
    },
  },
  // UI-12: the admin keeps the design system's look. Its neutral is slate
  // (not gray), its accent is the theme colour brand-* (not a fixed indigo),
  // and boxes and buttons come from Components/ui (Card, Button) rather than
  // a hand-made "rounded border" or a dark "bg-slate-900" button. The
  // storefront, the public home page and the logo have their own colours.
  {
    files: ['resources/js/**/*.{ts,tsx}'],
    ignores: [
      'resources/js/**/*.test.{ts,tsx}',
      'resources/js/Components/Storefront/**',
      'resources/js/Pages/Storefront/**',
      'resources/js/Pages/Welcome.tsx',
      'resources/js/Components/Welcome/**', // Phase S1: the home page's request form, in the home page's colours
      'resources/js/Components/Brand.tsx',
    ],
    rules: {
      'no-restricted-syntax': [
        'error',
        ...[
          ['\\bgray-\\d', 'Use slate-* (the design system neutral), not gray-*.'],
          ['\\bindigo-\\d', 'Use brand-* (the user\'s theme colour), not a fixed indigo-*.'],
          ['(^|\\s)rounded border(\\s|$)', 'Use Card (Components/ui/Page) or rounded-xl/2xl with border-slate-200, not a bare "rounded border".'],
          ['(^|\\s)rounded bg-slate-900(\\s|$)', 'Use Button variant="primary" (Components/ui/Button), not a hand-made dark button.'],
        ].flatMap(([pattern, message]) => [
          { selector: `JSXAttribute[name.name='className'] Literal[value=/${pattern}/]`, message },
          { selector: `JSXAttribute[name.name='className'] TemplateElement[value.raw=/${pattern}/]`, message },
        ]),
      ],
    },
  },
);
