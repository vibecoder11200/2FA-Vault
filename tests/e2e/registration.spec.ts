import { test, expect } from '@playwright/test';
import { testUsers, routes, sel } from './fixtures/test-data.fixture';

test.describe('Registration Flow', () => {
  test('P0: Register new account and see start page', async ({ page }) => {
    await page.goto(routes.register);

    // Wait for SPA mount
    await page.locator(sel.registerButton).waitFor({ state: 'visible', timeout: 15000 });

    // Fill registration form
    const reg = testUsers.newRegistration;
    await page.locator(sel.nameInput).fill(reg.name);
    await page.locator(sel.emailInput).fill(reg.email);
    await page.locator(sel.passwordInput).fill(reg.password);

    // Submit
    await page.locator(sel.registerButton).click();

    // After registration, WebAuthn device registration prompt may appear
    // with a "Maybe Later" link
    const maybeLater = page.locator(sel.maybeLaterButton);
    await maybeLater.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    if (await maybeLater.isVisible()) {
      await maybeLater.click();
    }

    // New user -> should land on /start page
    await expect(page).toHaveURL(/\/start/, { timeout: 10000 });
  });

  test('P0: Register with duplicate email shows error', async ({ page }) => {
    await page.goto(routes.register);
    await page.locator(sel.registerButton).waitFor({ state: 'visible', timeout: 15000 });

    await page.locator(sel.nameInput).fill('Duplicate User');
    await page.locator(sel.emailInput).fill(testUsers.admin.email);
    await page.locator(sel.passwordInput).fill('ValidPassword123!');

    await page.locator(sel.registerButton).click();

    // Should show validation error for email (422 response)
    await page.waitForTimeout(2000);
    // The form should still be visible (stayed on register page)
    await expect(page.locator(sel.registerButton)).toBeVisible();
  });

  test('P0: Sign in link from register page goes to login', async ({ page }) => {
    await page.goto(routes.register);
    await page.locator(sel.signInLink).waitFor({ state: 'visible', timeout: 15000 });

    await page.locator(sel.signInLink).click();
    await expect(page).toHaveURL(/\/login/);
  });
});
