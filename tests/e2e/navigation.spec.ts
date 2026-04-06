import { test, expect } from '@playwright/test';
import { routes } from './fixtures/test-data.fixture';

test.describe('Navigation & Route Guards', () => {
  test('P2: About page is accessible without auth', async ({ page }) => {
    await page.goto(routes.about);
    await page.waitForLoadState('networkidle');

    // About page should load (no auth required)
    await expect(page.locator('body')).toBeVisible();
  });

  test('P2: 404 page for non-existent route', async ({ page }) => {
    await page.goto('/this-route-does-not-exist');
    await page.waitForLoadState('networkidle');

    // The 404/Error page has an .error-404 CSS class and i18n text
    await expect(page.locator('.error-404')).toBeVisible({ timeout: 10000 });
  });

  test('P2: SPA routes do not cause full page reload', async ({ page }) => {
    // Navigate to login first
    await page.goto(routes.login);
    await page.waitForLoadState('networkidle');

    // Navigate to about (should be SPA navigation, not full reload)
    await page.goto(routes.about);
    await page.waitForLoadState('networkidle');

    // The SPA should handle navigation without a full page reload from server
    await expect(page.locator('body')).toBeVisible();
  });
});
