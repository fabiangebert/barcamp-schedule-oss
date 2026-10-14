import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const configPath = path.resolve(__dirname, '../e2e-config.json');
const e2eConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));

const titleSelector = '[data-edit-id="session-0-0-title"]';
const cellSelector = '[id="0-0"]';

const rgb = (value) => value.replace(/\s/g, '');

const luminance = (color) => {
  const match = color.match(/\d+/g);
  if (!match) return 0;
  const [r, g, b] = match.map(Number);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

test.describe('Display mode E2E Tests', () => {
  test.use({ colorScheme: 'light' });

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');

      window.mockEventSources = [];
      class MockEventSource extends EventTarget {
        constructor(url) { super(); this.url = url; window.mockEventSources.push(url); setTimeout(() => { if (this.onopen) this.onopen(); this.dispatchEvent(new Event('open')); }, 0); }
        close() {}
      }
      window.EventSource = MockEventSource;
    }, e2eConfig.adminToken);
  });

  const openSchedule = async (page, hash = '') => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}${hash}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
    await expect(page.locator(titleSelector)).toBeVisible();
  };

  // The grid animates colour and size changes, so measurements have to settle
  // before they mean anything.
  const untilChanged = async (page, selector, read, from) => {
    await expect
      .poll(async () => page.locator(selector).evaluate(read), { timeout: 5000 })
      .not.toBe(from);
    await page.waitForTimeout(250);
    return page.locator(selector).evaluate(read);
  };

  test('starts on the light theme and the theme control shows the monitor icon', async ({ page }) => {
    await openSchedule(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('[data-testid="theme-icon-system"]')).toBeVisible();
  });

  test('dark theme repaints the session cell background', async ({ page }) => {
    await openSchedule(page);
    const readBg = (el) => getComputedStyle(el).backgroundColor;
    const before = await page.locator(cellSelector).evaluate(readBg);

    await page.getByTestId('theme-toggle').click();
    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    const after = await untilChanged(page, cellSelector, readBg, before);
    expect(rgb(after)).not.toBe(rgb(before));
    expect(luminance(after)).toBeLessThan(luminance(before));
  });

  test('dark theme keeps the session title legible against the cell', async ({ page }) => {
    await openSchedule(page);
    const readBg = (el) => getComputedStyle(el).backgroundColor;
    const readColor = (el) => getComputedStyle(el).color;
    const cellBefore = await page.locator(cellSelector).evaluate(readBg);
    const titleBefore = await page.locator(titleSelector).evaluate(readColor);

    await page.getByTestId('theme-toggle').click();
    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    const cell = await untilChanged(page, cellSelector, readBg, cellBefore);
    const title = await untilChanged(page, titleSelector, readColor, titleBefore);

    expect(luminance(title)).toBeGreaterThan(luminance(cell) + 100);
  });

  test('presentation mode enlarges the session title', async ({ page }) => {
    await openSchedule(page);
    const readSize = (el) => parseFloat(getComputedStyle(el).fontSize);
    const before = await page.locator(titleSelector).evaluate(readSize);

    await page.getByTestId('presentation-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');

    const after = await untilChanged(page, titleSelector, readSize, `${before}px`);
    expect(after).toBeGreaterThan(before);
  });

  test('presentation mode enlarges the small label fonts that used to be fixed pixels', async ({ page }) => {
    await openSchedule(page);
    const readSize = (el) => parseFloat(getComputedStyle(el).fontSize);
    // The room header is one of the label sizes migrated off fixed pixels.
    const roomHeader = page.locator('span:has-text("Plenary")').first();
    await expect(roomHeader).toBeVisible();
    const before = await roomHeader.evaluate(readSize);

    await page.getByTestId('presentation-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');
    await page.waitForTimeout(250);

    const after = await roomHeader.evaluate(readSize);
    expect(after).toBeGreaterThan(before);
  });

  test('the theme choice survives a reload', async ({ page }) => {
    await openSchedule(page);
    await page.getByTestId('theme-toggle').click();
    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await openSchedule(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('the presentation choice survives a reload', async ({ page }) => {
    await openSchedule(page);
    await page.getByTestId('presentation-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');

    await openSchedule(page);
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');
  });

  test('a url hash applies dark and presentation without any click', async ({ page }) => {
    await openSchedule(page, '&theme=dark&present=1');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');
  });

  test('a url hash overrides the stored theme without overwriting it', async ({ page }) => {
    await openSchedule(page, '&theme=dark');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => window.localStorage.getItem('theme'))).toBeNull();

    // Dropping the parameter from the live hash clears the override again.
    await openSchedule(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });

  test('a url hash theme does not stick once the parameter is dropped', async ({ page }) => {
    await openSchedule(page, '&theme=dark&present=1');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');

    await openSchedule(page, '&present=1');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('html')).toHaveAttribute('data-present', 'true');

    await openSchedule(page);
    await expect(page.locator('html')).toHaveAttribute('data-present', 'false');
  });

  test('the edit mode hash still works alongside the display params', async ({ page }) => {
    await openSchedule(page, '&mode=edit&theme=dark&present=1');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('[data-testid="presentation-toggle"]')).toHaveAttribute('aria-pressed', 'true');
  });
});

test.describe('Display mode follows the operating system', () => {
  test.use({ colorScheme: 'dark' });

  test.beforeEach(async ({ context, page }) => {
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');

      window.mockEventSources = [];
      class MockEventSource extends EventTarget {
        constructor(url) { super(); this.url = url; window.mockEventSources.push(url); setTimeout(() => { if (this.onopen) this.onopen(); this.dispatchEvent(new Event('open')); }, 0); }
        close() {}
      }
      window.EventSource = MockEventSource;
    }, e2eConfig.adminToken);
  });

  test('renders dark when the system prefers dark and no choice was made', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('an explicit light choice wins over a dark system preference', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });
});
