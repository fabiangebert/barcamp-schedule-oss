import { test, expect } from '@playwright/test';

test.describe('Login View Loading State E2E Tests', () => {
  test('should freeze the form and show loading spinner when submitting the form', async ({ page }) => {
    // 1. Intercept the magic-link API request and delay its fulfillment
    let routePromise;
    await page.route('**/api/auth/magic-link', async (route) => {
      // Store the route to fulfill it later
      routePromise = route;
    });

    // 2. Navigate to the login page (root url redirects here if not logged in)
    await page.goto('/');

    // 3. Find the email input and submit button
    const emailInput = page.locator('input[type="email"]');
    const submitBtn = page.locator('button[type="submit"]');

    await expect(emailInput).toBeVisible();
    await expect(submitBtn).toBeVisible();

    // 4. Fill the email field with a valid email address
    await emailInput.fill('user@example.com');
    await expect(submitBtn).toBeEnabled();

    // 5. Submit the form
    await submitBtn.click();

    // 6. Assert that while the API request is pending:
    // - The email input is disabled (frozen)
    await expect(emailInput).toBeDisabled();

    // - The submit button is disabled (frozen)
    await expect(submitBtn).toBeDisabled();

    // - The loading spinner is visible inside the button
    const spinner = submitBtn.locator('.animate-spin');
    await expect(spinner).toBeVisible();

    // 7. Fulfill the intercepted API call
    if (routePromise) {
      await routePromise.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Magic link sent' })
      });
    }

    // 8. Assert that after the API call completes:
    // - The email input is re-enabled and cleared
    await expect(emailInput).toBeEnabled();
    await expect(emailInput).toHaveValue('');

    // - If we fill the email input again, the button should still be disabled due to the 5-second cooldown
    await emailInput.fill('user@example.com');
    await expect(submitBtn).toBeDisabled();

    // - Wait 5 seconds for the cooldown to finish
    await page.waitForTimeout(5000);

    // - The submit button is finally re-enabled after the cooldown
    await expect(submitBtn).toBeEnabled();

    // - The loading spinner is no longer visible
    await expect(spinner).not.toBeVisible();
  });
});
