import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Documentation Overview Tabs E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');

      window.mockEventSources = [];
      class MockEventSource extends EventTarget {
        constructor(url) { super(); this.url = url; window.mockEventSources.push(this); setTimeout(() => { if (this.onopen) this.onopen(); this.dispatchEvent(new Event('open')); }, 0); }
        close() {}
      }
      window.EventSource = MockEventSource;
    }, e2eConfig.adminToken);

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  });

  test('switches between Documentation, Leaderboard, and Feedback tabs', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /documentation|dokumentation/i }).click();

    await expect(page.getByText(/documentation overview|dokumentationsübersicht/i).first()).toBeVisible({ timeout: 5000 });

    const tabBar = page.locator('div').filter({ hasText: /leaderboard|rangliste/i }).filter({ hasText: /feedback/i }).first();

    await tabBar.getByRole('button', { name: /leaderboard|rangliste/i }).click();
    await expect(page.getByText(/rank|platz|rang/i).first()).toBeVisible();

    await tabBar.getByRole('button', { name: /^feedback$/i }).click();
    await expect(page.getByText(/how likely are you to recommend/i).first()).toBeVisible();
    await expect(page.getByText(/general feedback/i).first()).toBeVisible();

    await tabBar.getByRole('button', { name: /^documentation$/i }).click();
    await expect(page.getByText(/documentation overview|dokumentationsübersicht/i).first()).toBeVisible();
  });

  test('shows computed feedback statistics after answers are submitted', async ({ page, request }) => {
    const scheduleId = e2eConfig.scheduleId;
    const authToken = e2eConfig.adminToken;
    const baseAPI = `http://localhost:8000/api/schedules`;

    const feedback1 = {
      content: JSON.stringify({
        feedback_answers: { q1: 8, q2: 'Really enjoyed it!' }
      })
    };
    const feedback2 = {
      content: JSON.stringify({
        feedback_answers: { q1: 6, q2: 'Good event overall' }
      })
    };
    const feedback3 = {
      content: JSON.stringify({
        feedback_answers: { q1: 9, q2: 'Excellent organization!' }
      })
    };

    const headers = {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    };

    const r1 = await request.post(`${baseAPI}/${scheduleId}/sessions/09:00/Room A/documentation`, { data: feedback1, headers });
    expect(r1.ok()).toBeTruthy();
    const r2 = await request.post(`${baseAPI}/${scheduleId}/sessions/10:00/Room B/documentation`, { data: feedback2, headers });
    expect(r2.ok()).toBeTruthy();
    const r3 = await request.post(`${baseAPI}/${scheduleId}/sessions/11:00/Room C/documentation`, { data: feedback3, headers });
    expect(r3.ok()).toBeTruthy();

    await page.goto(`/#schedule=${scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /documentation|dokumentation/i }).click();
    await expect(page.getByText(/documentation overview|dokumentationsübersicht/i).first()).toBeVisible({ timeout: 5000 });

    const tabBar = page.locator('div').filter({ hasText: /leaderboard|rangliste/i }).filter({ hasText: /feedback/i }).first();
    await tabBar.getByRole('button', { name: /^feedback$/i }).click();

    const ratingQuestion = page.getByText('How likely are you to recommend this event?');
    await expect(ratingQuestion).toBeVisible();

    await expect(page.getByText('3 responses').first()).toBeVisible();
    await expect(page.getByText('Ø 7.7 / 10')).toBeVisible();

    const freeTextQuestion = page.getByText('General Feedback');
    await expect(freeTextQuestion).toBeVisible();

    await expect(page.getByText('Really enjoyed it!')).toBeVisible();
    await expect(page.getByText('Good event overall')).toBeVisible();
    await expect(page.getByText('Excellent organization!')).toBeVisible();

    await expect(page.getByRole('button', { name: /export feedback csv/i })).toBeVisible();

    // The download must carry the submitted answers, not just a header row.
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: /export feedback csv/i }).click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe(`feedback_${scheduleId}.csv`);

    const csv = fs.readFileSync(await download.path(), 'utf8');
    expect(csv.startsWith('Question')).toBe(true);
    expect(csv).not.toContain('\uFEFF');
    expect(csv).toContain('Really enjoyed it!');
    expect(csv).toContain('Excellent organization!');
    expect(csv).toContain('8/10');
  });
});
