import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Admin User Management E2E Tests', () => {

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

  test('should add, update role, and delete a user', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h2')).toContainText(/available schedules|verfügbare zeitpläne/i);

    const adminBtn = page.getByRole('button', { name: /^admin$/i });
    await expect(adminBtn).toBeVisible();
    await adminBtn.click();

    await expect(page.locator('h2').first()).toContainText(/user management|benutzerverwaltung/i);

    const newNameInput = page.locator('#new-user-name');
    const newEmailInput = page.locator('#new-user-email');
    await expect(newNameInput).toBeVisible();
    await expect(newEmailInput).toBeVisible();

    await newNameInput.fill('E2E Test User');
    await newEmailInput.fill('e2e-test-user@example.com');

    const addBtn = page.getByRole('button', { name: /add user|benutzer hinzufügen/i });
    await expect(addBtn).toBeVisible();
    await addBtn.click();

    const userRow = page.locator('tr').filter({ hasText: 'e2e-test-user@example.com' });
    await expect(userRow).toBeVisible({ timeout: 5000 });
    await expect(userRow.locator('input')).toHaveValue('E2E Test User');

    const roleSelect = userRow.locator('select');
    await expect(roleSelect).toHaveValue('user');
    await roleSelect.selectOption('admin');
    await expect(roleSelect).toHaveValue('admin');

    const deleteBtn = userRow.getByRole('button', { name: /delete|löschen/i });
    await expect(deleteBtn).toBeVisible();

    page.once('dialog', async dialog => {
      await dialog.accept();
    });
    await deleteBtn.click();

    await expect(page.locator('tr').filter({ hasText: 'e2e-test-user@example.com' })).not.toBeVisible();
  });
});
