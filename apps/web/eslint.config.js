import js from '@eslint/js'
import globals from 'globals'
import prettier from 'eslint-config-prettier'
import vue from 'eslint-plugin-vue'
import ts from 'typescript-eslint'

/**
 * The rules a typechecker cannot see.
 *
 * `vue-tsc` already runs on every build, so this is deliberately not a second opinion about
 * types — it is here for the Vue-shaped mistakes (a template referencing something that does
 * not exist, a `v-for` without a key, a mutated prop) and for unused code.
 *
 * Formatting is Prettier's, and `eslint-config-prettier` last turns off every rule that
 * would argue with it.
 */
export default ts.config(
  { ignores: ['dist/**', 'src/api/documents.gen.d.ts', 'test-results/**', 'playwright-report/**'] },
  js.configs.recommended,
  ...ts.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: ts.parser } },
  },
  {
    files: ['src/**', 'e2e/**'],
    languageOptions: { globals: globals.browser },
  },
  {
    files: ['scripts/**', '*.config.*'],
    languageOptions: { globals: globals.node },
  },
  {
    rules: {
      // A leading underscore is the convention for "destructured only to be dropped",
      // which is how `v-for="(_, seat) in seats"` and schema-stripping rest spreads read.
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },
  prettier,
)
