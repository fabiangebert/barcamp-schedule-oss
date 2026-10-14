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

test.describe('Name Update E2E', () => {

  test.afterAll(async () => {
    execSync(
      `DB_PATH=${dbPath} JWT_SECRET=put-at-least-32-characters-here-1234567890 php ${rootDir}/../tests/seed_e2e.php`,
      { cwd: rootDir, timeout: 15000 }
    );
    reloadConfig();
  });

  test.beforeEach(async ({ context, page }) => {
    execSync(
      `DB_PATH=${dbPath} JWT_SECRET=put-at-least-32-characters-here-1234567890 php ${rootDir}/../tests/seed_e2e.php`,
      { cwd: rootDir, timeout: 15000 }
    );
    e2eConfig = reloadConfig();

    await context.addInitScript((token) => {
      if (!window.localStorage.getItem('token')) {
        window.localStorage.setItem('token', token);
      }
      if (!window.localStorage.getItem('role')) {
        window.localStorage.setItem('role', 'admin');
      }
      if (!window.localStorage.getItem('user')) {
        window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      }
      if (!window.localStorage.getItem('userName')) {
        window.localStorage.setItem('userName', 'E2E Admin');
      }
      if (!window.localStorage.getItem('isNameCustomized')) {
        window.localStorage.setItem('isNameCustomized', 'true');
      }
    }, e2eConfig.adminToken);

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));

    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
  });

  test('allows user to update name via NameModal and persists on reload', async ({ page }) => {
    const userNameTrigger = page.getByTitle(/change name|name ändern/i);
    await expect(userNameTrigger).toBeVisible();
    await expect(userNameTrigger).toContainText('E2E Admin');

    await userNameTrigger.click();

    const modal = page.getByRole('heading', { name: /welcome|willkommen/i });
    await expect(modal).toBeVisible();

    const nameInput = page.getByLabel('Name');
    await expect(nameInput).toBeVisible();
    await nameInput.fill('');
    await nameInput.fill('Updated Name');

    await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/user/name') && r.request().method() === 'POST'),
      page.getByRole('button', { name: /save|speichern/i }).click()
    ]);

    await expect(modal).not.toBeVisible();

    await expect(userNameTrigger).toContainText('Updated Name');

    await page.reload();
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    const userNameAfterReload = page.getByTitle(/change name|name ändern/i);
    await expect(userNameAfterReload).toContainText('Updated Name');
  });

});
