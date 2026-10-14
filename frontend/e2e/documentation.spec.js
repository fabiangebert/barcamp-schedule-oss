import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

const fixturesDir = path.resolve(__dirname, 'fixtures');
const validPngPath = path.join(fixturesDir, 'valid.png');
const maliciousPngPath = path.join(fixturesDir, 'malicious.png');

test.describe('Barcamp Schedule Documentation Upload & Security E2E Tests', () => {

  test.beforeAll(async () => {
    if (!fs.existsSync(fixturesDir)) {
      fs.mkdirSync(fixturesDir, { recursive: true });
    }
    if (!fs.existsSync(validPngPath)) {
      const validPngBuffer = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'base64'
      );
      fs.writeFileSync(validPngPath, validPngBuffer);
    }
    if (!fs.existsSync(maliciousPngPath)) {
      fs.writeFileSync(maliciousPngPath, 'This is not an image - it is a plain text file masquerading as a PNG.');
    }
  });

  test.afterAll(async () => {
    if (fs.existsSync(validPngPath)) fs.unlinkSync(validPngPath);
    if (fs.existsSync(maliciousPngPath)) fs.unlinkSync(maliciousPngPath);
    if (fs.existsSync(fixturesDir)) fs.rmdirSync(fixturesDir);
  });

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ email: 'admin@example.com', role: 'admin' }));
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

    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
  });

  test('should accept a valid PNG upload and reject a spoofed text file with MIME error', async ({ page }) => {
    // -----------------------------------------------------------------------
    // A. OPEN DOCUMENTATION MODAL
    // -----------------------------------------------------------------------
    // Find any occupied session cell (the editor test may have moved sessions)
    const occupiedCell = page.locator('[id]').filter({ hasText: /Session/ }).first();
    await expect(occupiedCell).toBeVisible();
    await occupiedCell.click();

    // Wait for the documentation modal to appear (session title contains "Session")
    const modalHeader = page.locator('h2').filter({ hasText: /Session/ }).first();
    await expect(modalHeader).toBeVisible();

    // The second input[type=file] is the regular file upload (not camera capture)
    const fileInput = page.locator('input[type="file"]').nth(1);
    await expect(fileInput).toBeAttached();

    // -----------------------------------------------------------------------
    // B. UPLOAD VALID PNG - VERIFY PREVIEW
    // -----------------------------------------------------------------------
    await fileInput.setInputFiles(validPngPath);

    // A thumbnail preview should appear as a data-URI image
    const preview = page.locator('img[src^="data:image/png"]');
    await expect(preview).toBeVisible();

    const contributeBtn = page.getByRole('button', { name: /contribute|beitragen/i });
    await expect(contributeBtn).toBeEnabled();

    // -----------------------------------------------------------------------
    // C. SUBMIT VALID PNG - VERIFY SUCCESSFUL UPLOAD
    // -----------------------------------------------------------------------
    await contributeBtn.click();

    // After submission the documented image appears in the scrollable doc list
    // with a server-side path containing /api/uploads/
    const uploadedImage = page.locator('img[src*="/api/uploads/"]');
    await expect(uploadedImage).toBeVisible({ timeout: 15000 });

    // -----------------------------------------------------------------------
    // D. UPLOAD SPOOFED "malicious.png" (text content, .png extension)
    // -----------------------------------------------------------------------
    await fileInput.setInputFiles(maliciousPngPath);

    // Client-side MIME check passes because the extension is .png,
    // so a preview also shows for the spoofed file
    await expect(preview).toBeVisible();

    // Submit again - this will fail server-side MIME validation
    await contributeBtn.click();

    // -----------------------------------------------------------------------
    // E. VERIFY SERVER-SIDE MIME REJECTION NOTIFICATION
    // -----------------------------------------------------------------------
    const errorNotification = page.getByText('Unsupported MIME type: text/plain');
    await expect(errorNotification).toBeVisible({ timeout: 15000 });
  });
});
