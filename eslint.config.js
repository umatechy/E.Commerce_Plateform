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
);
