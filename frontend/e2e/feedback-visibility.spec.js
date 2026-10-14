import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Feedback Visibility E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'user');
      window.localStorage.setItem('user', JSON.stringify({ id: 2, email: 'user@example.com', role: 'user' }));
      window.localStorage.setItem('userName', 'E2E User');

      window.mockEventSources = [];
      class MockEventSource extends EventTarget {
        constructor(url) { super(); this.url = url; window.mockEventSources.push(this); setTimeout(() => { if (this.onopen) this.onopen(); this.dispatchEvent(new Event('open')); }, 0); }
        close() {}
      }
      window.EventSource = MockEventSource;
    }, e2eConfig.userToken);

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  });

  test('user sees private guest feedback and downloads the LLM export', async ({ page, request }) => {
    const scheduleId = e2eConfig.scheduleId;
    const baseAPI = `http://localhost:8000/api/schedules`;

    // Seed a private anonymous guest feedback entry (the organizer model:
    // logged-in users must see it, even though guests cannot)
    const seed = await request.post(
      `${baseAPI}/${scheduleId}/sessions/event/event/documentation`,
      {
        data: {
          content: JSON.stringify({
            feedback_answers: {
              q1: 8,
              q2: 'Secret private guest feedback',
              q3: 'Workshop'
            }
          }),
          guest_id: 'e2e-private-guest',
          is_public: false
        },
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${e2eConfig.guestToken}`
        }
      }
    );
    expect(seed.ok()).toBeTruthy();

    await page.goto(`/#schedule=${scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule', { timeout: 10000 });

    await page.getByRole('button', { name: /documentation|dokumentation/i }).click();
    await expect(page.getByText(/documentation overview|dokumentationsübersicht/i).first()).toBeVisible({ timeout: 5000 });

    const tabBar = page.locator('div').filter({ hasText: /leaderboard|rangliste/i }).filter({ hasText: /feedback/i }).first();
    await tabBar.getByRole('button', { name: /^feedback$/i }).click();

    // Private guest answers must be visible to the user role
    await expect(page.getByText('Secret private guest feedback').first()).toBeVisible({ timeout: 10000 });
    await expect(page.getByText('Ø 8.0 / 10')).toBeVisible();

    // LLM export must download for the user role
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: /export for llm|export für llm/i }).click();
    const download = await downloadPromise;
    expect(await download.path()).toBeTruthy();
  });
});
