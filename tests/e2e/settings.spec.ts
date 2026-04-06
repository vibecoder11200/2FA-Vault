import { test, expect } from './fixtures/auth.fixture';
import { routes } from './fixtures/test-data.fixture';

test.describe('Settings', () => {
  test.beforeEach(async ({ loginAsAdmin }) => {
    // Authenticated as admin
  });

  test('P2: Settings options page loads', async ({ page }) => {
    await page.goto(routes.settingsOptions);
    await page.waitForLoadState('networkidle');

    // Page should show the options form with controls
    // The Options.vue renders radio buttons for theme, select for layout, etc.
    await expect(page.locator('.label').first()).toBeVisible({ timeout: 10000 });
  });

  test('P2: Settings backup page loads', async ({ page }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    // Should show the backup/restore page
    await expect(page.locator('body')).toBeVisible();
    // The backup page should have some content (form or buttons)
    await expect(page.locator('.content, .label, form, button').first()).toBeVisible({ timeout: 10000 });
  });
});
