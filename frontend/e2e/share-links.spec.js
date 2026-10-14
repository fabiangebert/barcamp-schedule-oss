import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

const API_BASE = 'http://localhost:8000';

test.describe('Share Links E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');

      window.mockEventSources = [];
      class MockEventSource extends EventTarget {
        constructor(url) {
          super();
          this.url = url;
          window.mockEventSources.push(this);
          setTimeout(() => {
            if (this.onopen) this.onopen();
            this.dispatchEvent(new Event('open'));
          }, 0);
        }
        close() {}
      }
      window.EventSource = MockEventSource;
    }, e2eConfig.adminToken);

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  });

  test('admin creates, views, and deletes share links', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h2')).toContainText(/available schedules|verfügbare zeitpläne/i);

    const scheduleRow = page.locator('tr').filter({ hasText: 'E2E Test Schedule' });
    const shareBtn = scheduleRow.getByRole('button', { name: /share|teilen/i });
    await expect(shareBtn).toBeVisible();
    await shareBtn.click();

    await expect(page.getByText(/share schedule|zeitplan teilen/i).first()).toBeVisible({ timeout: 5000 });

    const createBtn = page.getByRole('button', { name: /^create$/i });
    await expect(createBtn).toBeVisible();
    await createBtn.click();

    const linkUrl = page.locator('.font-mono').first();
    await expect(linkUrl).toBeVisible({ timeout: 10000 });
    const urlText = await linkUrl.textContent();
    expect(urlText).toContain('share_token=');

    const deleteBtn = page.getByRole('button', { name: /^delete$/i }).nth(1);
    await expect(deleteBtn).toBeVisible();

    page.once('dialog', async dialog => { await dialog.accept(); });
    await deleteBtn.click();

    await expect(linkUrl).not.toBeVisible();

    const closeBtn = page.getByRole('button', { name: /close|schließen/i });
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();
  });

  test('guest accesses schedule via share link and cannot edit', async ({ browser }) => {
    const createRes = await fetch(`${API_BASE}/api/share-links`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${e2eConfig.adminToken}`
      },
      body: JSON.stringify({
        schedule_id: e2eConfig.scheduleId,
        expires_at: '2099-12-31 23:59:59'
      })
    });
    expect(createRes.ok).toBe(true);
    const createData = await createRes.json();
    const shareToken = createData.token;

    const loginRes = await fetch(`${API_BASE}/api/auth/login?share_token=${shareToken}`);
    expect(loginRes.ok).toBe(true);
    const loginData = await loginRes.json();
    const guestJwt = loginData.token;
    const redirectSchedule = loginData.redirect_schedule;

    const context = await browser.newContext();
    await context.addInitScript(({ token, scheduleId }) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'guest');
      window.localStorage.setItem('user', JSON.stringify({ role: 'guest' }));
      window.localStorage.setItem('redirect_schedule', scheduleId);
    }, { token: guestJwt, scheduleId: redirectSchedule });

    const page = await context.newPage();
    page.on('console', msg => console.log('GUEST LOG:', msg.text()));

    await page.goto(`/#schedule=${redirectSchedule}`);

    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule', { timeout: 15000 });

    await expect(page.getByRole('button', { name: /edit|bearbeiten/i })).not.toBeVisible();

    await expect(page.locator('[id="0-0"]')).toBeVisible();

    await context.close();
  });

  test('guest with deleted schedule can log out from the error dialog', async ({ browser }) => {
    const createRes = await fetch(`${API_BASE}/api/share-links`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${e2eConfig.adminToken}`
      },
      body: JSON.stringify({
        schedule_id: e2eConfig.scheduleId,
        expires_at: '2099-12-31 23:59:59'
      })
    });
    expect(createRes.ok).toBe(true);
    const createData = await createRes.json();

    const loginRes = await fetch(`${API_BASE}/api/auth/login?share_token=${createData.token}`);
    expect(loginRes.ok).toBe(true);
    const loginData = await loginRes.json();
    const guestJwt = loginData.token;

    const context = await browser.newContext();
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'guest');
      window.localStorage.setItem('user', JSON.stringify({ role: 'guest' }));
      window.localStorage.setItem('userName', 'E2E Guest');
      window.localStorage.setItem('isNameCustomized', 'true');
      // Schedule the guest was linked to has been deleted in the meantime
      window.localStorage.setItem('redirect_schedule', 'deleted-schedule-e2e');
    }, guestJwt);

    const page = await context.newPage();

    let scheduleFetches = 0;
    page.on('request', req => {
      if (req.url().includes('/api/schedules/deleted-schedule-e2e')) {
        scheduleFetches++;
      }
    });

    await page.goto('/');

    // Error dialog shows once, without a refetch loop
    const errorDialog = page.locator('div[style*="z-index: 2000"]');
    await expect(errorDialog).toBeVisible({ timeout: 15000 });
    await expect(errorDialog).toContainText(/schedule not found|zeitplan nicht gefunden/i);
    await expect.poll(() => scheduleFetches, { timeout: 5000 }).toBe(1);

    // Clicking Log out in the dialog logs the guest out immediately
    await errorDialog.getByRole('button', { name: /logout|abmelden/i }).click();

    await expect(errorDialog).not.toBeVisible();
    await expect(page.locator('input[type="email"]')).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('token'))).toBeNull();
    expect(await page.evaluate(() => localStorage.getItem('role'))).toBeNull();
    expect(await page.evaluate(() => localStorage.getItem('redirect_schedule'))).toBeNull();

    await context.close();
  });
});
