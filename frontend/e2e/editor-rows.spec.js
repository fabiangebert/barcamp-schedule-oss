import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Schedule Editor Row Management E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');
    }, e2eConfig.adminToken);

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  });

  test('adds and removes a room', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await page.getByRole('button', { name: /add room|raum hinzufügen/i }).click();

    const newRoomHeader = page.locator('span').filter({ hasText: /^New Room/ }).first();
    await expect(newRoomHeader).toBeVisible({ timeout: 5000 });

    await page.getByRole('button', { name: /view|ansehen/i }).click();
    await page.waitForTimeout(1000);

    const newRoomSpan = page.locator('span').filter({ hasText: /^New Room/ }).first();
    await expect(newRoomSpan).toBeVisible();

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await newRoomSpan.hover();
    const deleteRoomBtn = newRoomSpan.locator('xpath=..').getByTitle(/delete room|raum löschen/i);
    await expect(deleteRoomBtn).toBeVisible();
    await deleteRoomBtn.click();
    await page.getByRole('button', { name: /^delete|löschen$/i }).last().click();
    await expect(newRoomSpan).not.toBeVisible();
  });

  test('adds and removes a timeslot', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await page.getByRole('button', { name: /add timeslot|zeitslot hinzufügen/i }).click();

    const newTimeslotHeader = page.locator('span').filter({ hasText: /^New Timeslot/ }).first();
    await expect(newTimeslotHeader).toBeVisible({ timeout: 5000 });

    await page.getByRole('button', { name: /view|ansehen/i }).click();
    await page.waitForTimeout(1000);

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    await newTimeslotHeader.hover();
    const deleteTimeslotBtn = newTimeslotHeader.locator('xpath=..').getByTitle(/delete timeslot|zeitslot löschen/i);
    await expect(deleteTimeslotBtn).toBeVisible();
    await deleteTimeslotBtn.click();
    await page.getByRole('button', { name: /^delete|löschen$/i }).last().click();
    await expect(newTimeslotHeader).not.toBeVisible();
  });

  test('deletes a session', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const cell = page.locator('[id="0-0"]');
    await expect(cell).toBeVisible();

    await cell.hover();

    const deleteSessionBtn = cell.getByTitle(/delete session|sitzung löschen/i);
    await expect(deleteSessionBtn).toBeVisible();
    await deleteSessionBtn.click();

    await page.getByRole('button', { name: /^delete|löschen$/i }).last().click();

    const deletedTitle = cell.getByText(/\(Title\)|\(Titel\)/i);
    await expect(deletedTitle).toBeVisible();
  });
});
