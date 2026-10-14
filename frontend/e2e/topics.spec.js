import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Topics E2E Tests', () => {

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

  test('creates a new topic', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const plusBtn = page.getByTitle(/add new topic|neues thema/i);
    await expect(plusBtn).toBeVisible();
    await plusBtn.click();

    const nameInput = page.getByPlaceholder(/topic name|themennamen/i);
    await expect(nameInput).toBeVisible();
    await nameInput.fill('Science');

    const doneBtn = page.getByRole('button', { name: /done|fertig/i });
    await expect(doneBtn).toBeVisible();
    await doneBtn.click();

    await expect(page.getByText('Science').first()).toBeVisible();

    await expect.poll(async () => {
      const res = await page.request.get(`/api/schedules/${e2eConfig.scheduleId}`, {
        headers: { Authorization: `Bearer ${e2eConfig.adminToken}` }
      });
      const data = await res.json();
      return data.data?.data?.topics?.some(t => t.name === 'Science');
    }, { timeout: 10000 }).toBe(true);

    await page.getByRole('button', { name: /view|ansehen/i }).click();
  });

  test('edits a topic name', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await expect(page.getByText('Tech').first()).toBeVisible();
    await page.getByText('Tech').first().click();

    const nameInput = page.getByPlaceholder(/topic name|themennamen/i);
    await expect(nameInput).toBeVisible();
    await nameInput.fill('Technology');

    const doneBtn = page.getByRole('button', { name: /done|fertig/i });
    await expect(doneBtn).toBeVisible();
    await doneBtn.click();

    await expect(page.getByText('Technology').first()).toBeVisible();
    await expect(page.getByText('Tech', { exact: true })).not.toBeVisible();
  });

  test('deletes a topic', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await page.getByText('Design').first().click();

    const deleteBtn = page.getByRole('button', { name: /^delete$/i });
    await expect(deleteBtn).toBeVisible();

    await deleteBtn.click();

    await page.getByRole('button', { name: /^delete$|^löschen$/i }).last().click();

    await expect(page.getByText('Design', { exact: true })).not.toBeVisible();
  });

  test('assigns topic via dropdown in edit mode', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const topicBadge = page.locator('[data-edit-id="session-0-0-topic"]');
    await expect(topicBadge).toBeVisible({ timeout: 5000 });
    await topicBadge.click();

    const dropdown = page.locator('.topic-dropdown');
    await expect(dropdown).toBeVisible();

    await dropdown.getByText('Technology').click();

    await expect(topicBadge).toHaveClass(/topic-1/);

    await expect.poll(async () => {
      const res = await page.request.get(`/api/schedules/${e2eConfig.scheduleId}`, {
        headers: { Authorization: `Bearer ${e2eConfig.adminToken}` }
      });
      const data = await res.json();
      const sessions = data.data?.data?.sessions ?? [];
      return sessions.some(s => s.timeslot === '10:00' && s.room === 'Plenary' && s.topic === 'Technology');
    }, { timeout: 10000 }).toBe(true);
  });
});
