import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const rootDir = path.resolve(__dirname, '..');
const configPath = path.resolve(rootDir, 'e2e-config.json');
const dbPath = path.resolve(rootDir, '../database_test.sqlite');

function reloadConfig() {
  return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

let e2eConfig = reloadConfig();

test.describe('Schedule Settings E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    execSync(
      `DB_PATH=${dbPath} JWT_SECRET=put-at-least-32-characters-here-1234567890 php ${rootDir}/../tests/seed_e2e.php`,
      { cwd: rootDir, timeout: 15000 }
    );
    e2eConfig = reloadConfig();

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

  test('renames schedule and changes date via settings modal', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const scheduleHeader = page.locator('h2').first();
    await scheduleHeader.click();

    await page.getByLabel(/^name$/i).fill('Renamed E2E Schedule');
    await page.getByLabel(/^date$/i).fill('2027-01-15');

    await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/schedules/') && r.request().method() === 'PATCH'),
      page.getByRole('button', { name: /^save$/i }).click()
    ]);

    await expect(page.locator('h2').first()).toContainText('Renamed E2E Schedule');
    await expect(page.locator('h2').first()).toContainText('2027-01-15');

    await scheduleHeader.click();

    await page.getByLabel(/^name$/i).fill('E2E Test Schedule');
    await page.getByLabel(/^date$/i).fill('2026-07-04');

    await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/schedules/') && r.request().method() === 'PATCH'),
      page.getByRole('button', { name: /^save$/i }).click()
    ]);

    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
    await expect(page.locator('h2').first()).toContainText('2026-07-04');
  });

  test('adds a feedback question and persists it', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const scheduleHeader = page.locator('h2').first();
    await scheduleHeader.click();

    await page.getByRole('button', { name: /add question|frage hinzufügen/i }).click();

    await page.getByLabel(/^question 1$/i).fill('What topic interested you most?');

    await page.getByRole('button', { name: /free text|freitext/i }).first().click();

    await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/schedules/') && r.request().method() === 'PATCH'),
      page.getByRole('button', { name: /^save$/i }).click()
    ]);

    await page.getByRole('button', { name: /view|ansehen/i }).click();

    await page.getByRole('button', { name: /feedback/i }).click();

    await expect(page.getByText('What topic interested you most?')).toBeVisible();

    await page.getByRole('button', { name: /close|schließen/i }).click({ force: true });
  });

  test('reorders and deletes feedback questions', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const scheduleHeader = page.locator('h2').first();
    await scheduleHeader.click();

    const questionCards = () => page.locator('div.overflow-y-auto.max-h-75 > div');

    await page.getByRole('button', { name: /add question|frage hinzufügen/i }).click();
    await questionCards().last().locator('input').first().fill('Should Move');
    await expect(questionCards().last().locator('input').first()).toHaveValue('Should Move');

    await page.getByRole('button', { name: /add question|frage hinzufügen/i }).click();
    await questionCards().last().locator('input').first().fill('Should Stay');
    await expect(questionCards().last().locator('input').first()).toHaveValue('Should Stay');

    await questionCards().nth(3).getByRole('button', { name: /move down|nach unten/i }).click();

    await questionCards().last().getByRole('button', { name: /remove question|frage entfernen/i }).click();

    await questionCards().last().getByRole('button', { name: /remove question|frage entfernen/i }).click();

    await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/schedules/') && r.request().method() === 'PATCH'),
      page.getByRole('button', { name: /^save$/i }).click()
    ]);

    await page.waitForTimeout(500);
    await page.getByRole('button', { name: /view|ansehen/i }).click();
    await page.waitForTimeout(300);

    await page.getByRole('button', { name: /feedback/i }).waitFor({ state: 'visible', timeout: 5000 });
    await page.getByRole('button', { name: /feedback/i }).click();
    await page.waitForTimeout(500);

    await expect(page.getByText('How likely are you to recommend this event?')).toBeVisible();
    await expect(page.getByText('General Feedback')).toBeVisible();
    await expect(page.getByText('Which session was your favorite?')).toBeVisible();
    await expect(page.getByText('Should Move')).not.toBeVisible();
    await expect(page.getByText('Should Stay')).not.toBeVisible();
  });
});
