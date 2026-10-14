import { defineConfig, devices } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const dbPath = path.resolve(__dirname, '../database_test.sqlite');

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.js',
  timeout: 30 * 1000,
  expect: {
    timeout: 5000
  },
  fullyParallel: false,
  workers: 1, // Crucial for SQLite database operations to avoid lock contentions
  retries: process.env.CI ? 1 : 0,
  reporter: 'list',
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    headless: true,
    viewport: { width: 1280, height: 720 },
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      command: 'php -S localhost:8000 public/api/index.php',
      url: 'http://localhost:8000/api/health',
      reuseExistingServer: !process.env.CI,
      cwd: '..',
      timeout: 30 * 1000,
      env: {
        APP_ENV: 'dev',
        APP_DEBUG: 'true',
        APP_URL: 'http://localhost:5173',
        DB_PATH: dbPath,
        ADMIN_EMAIL: 'admin@example.com',
        JWT_SECRET: 'put-at-least-32-characters-here-1234567890',
        JWT_EXPIRY_SECONDS: '3600',
        MERCURE_URL: 'http://localhost:8080/.well-known/mercure',
        MERCURE_PUBLIC_URL: 'http://localhost:8080/.well-known/mercure',
        MERCURE_JWT_SECRET: 'change-me-32-char-secret-for-testing!!!',
        MERCURE_JWT_TIMEOUT: '900',
      },
    },
    {
      command: 'npm run dev',
      url: 'http://localhost:5173',
      reuseExistingServer: !process.env.CI,
      timeout: 30 * 1000,
      env: {
        VITE_APP_TITLE: 'Barcamp Schedule',
        // Pin the test frontend to the local proxy. Playwright merges this over
        // process.env, so an ambient VITE_API_BASE (for example the absolute
        // production URL that CI sets from a repository variable) cannot make the
        // suite drive the real backend instead of the one started above.
        VITE_API_BASE: '/api',
        VITE_PROXY_TARGET: 'http://localhost:8000',
      },
    },
  ],
});
