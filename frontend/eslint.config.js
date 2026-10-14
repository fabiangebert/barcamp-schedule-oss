import js from "@eslint/js";
import globals from "globals";
import pluginVue from "eslint-plugin-vue";
import { defineConfig } from "eslint/config";
import skipFormatting from "@vue/eslint-config-prettier/skip-formatting";
import * as importPlugin from 'eslint-plugin-import-x';

export default defineConfig([
  // 1. Global Ignores (Replaces .eslintignore)
  {
    ignores: ['**/dist/**', '**/dist-ssr/**', '**/coverage/**', 'e2e/**']
  },

  // 2. Node.js overrides (vite.config.js, run-e2e.js)
  {
    files: ['vite.config.js', 'run-e2e.js', 'playwright.config.js', 'vitest.config.js'],
    languageOptions: {
      globals: globals.node,
    },
  },

  // 3. Base JS Recommended & Browser Globals
  {
    files: ['**/*.{js,mjs,cjs,vue}'],
    plugins: {
      js,
      'import-x': importPlugin
    },
    languageOptions: {
      globals: globals.browser,
      sourceType: 'module'
    },
    settings: {
      'import-x/resolver': {
        node: true
      }
    },
    rules: {
      ...js.configs.recommended.rules,
      'no-unused-vars': ['error', {
        'vars': 'all',
        'args': 'after-used',
        'ignoreRestSiblings': true,
        'caughtErrors': 'none'
      }],
    },
  },

  // 4. Vue Recommended Rules (Strict)
  ...pluginVue.configs['flat/recommended'],

  // 5. Prettier Compatibility (MUST come after Vue configs)
  // This disables ESLint rules that might conflict with Prettier
  skipFormatting,

  // 6. Custom Rule Overrides
  {
    name: 'app/custom-rules',
    rules: {
      'vue/multi-word-component-names': 'off',
      'vue/max-attributes-per-line': [
        'warn',
        {
          singleline: { max: 3 },
          multiline: { max: 1 }
        }
      ]
    }
  }
])
