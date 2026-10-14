import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

test.describe('Real-time SSE / Mercure Propagation', () => {

  test.skip(() => !process.env.MERCURE_HUB_RUNNING, 'Mercure hub not available — skipping realtime SSE tests');

  test('should propagate editor changes to viewer via Mercure SSE without page reload', async ({ context }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');
    }, e2eConfig.adminToken);

    // Open editor tab
    const editorPage = await context.newPage();
    editorPage.on('console', msg => console.log('EDITOR LOG:', msg.text()));
    await editorPage.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(editorPage.locator('h2').first()).toContainText('E2E Test Schedule');

    // Open viewer tab in same context (shares auth)
    const viewerPage = await context.newPage();
    viewerPage.on('console', msg => console.log('VIEWER LOG:', msg.text()));
    await viewerPage.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(viewerPage.locator('h2').first()).toContainText('E2E Test Schedule');

    // Enter edit mode in editor tab
    const editButton = editorPage.getByRole('button', { name: /edit|bearbeiten/i });
    await expect(editButton).toBeVisible();
    await editButton.click();
    await expect(editorPage.getByRole('button', { name: /view|ansehen/i })).toBeVisible();

    // Edit the title of session 0-0
    const titleInput = editorPage.locator('[data-edit-id="session-0-0-title"]');
    await expect(titleInput).toBeVisible();
    await titleInput.click();
    await titleInput.focus();
    await titleInput.fill('SSE Realtime Update');

    // Save by clicking outside the input
    await editorPage.locator('body').click({ position: { x: 10, y: 10 } });

    // Viewer tab should reflect the change WITHOUT page reload, via Mercure SSE push
    await expect(viewerPage.locator('[id="0-0"]')).toContainText('SSE Realtime Update', { timeout: 15000 });
  });
});
