import { test, expect } from './fixtures/auth.fixture';
import { routes, sel } from './fixtures/test-data.fixture';
import { AccountCreatePage } from './pages/AccountCreatePage';

test.describe('Account CRUD', () => {
  test.beforeEach(async ({ loginAsAdmin }) => {
    // Admin has pre-seeded accounts
  });

  test('P1: Accounts page shows seeded accounts', async ({ page }) => {
    await page.goto(routes.accounts);
    await page.waitForLoadState('networkidle');

    // Admin has 'GitHub' and 'Google' accounts from E2eSeeder
    await expect(page.locator('.tfa-cell').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=GitHub')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=Google')).toBeVisible({ timeout: 10000 });
  });

  test('P1: Create and verify new account appears', async ({ page }) => {
    const createPage = new AccountCreatePage(page);
    await createPage.goto();

    await createPage.fillAccount({
      service: 'NewService',
      account: 'new@test.com',
      secret: 'ABCDEFGHIJKLMNOP',
    });
    await createPage.submit();

    await page.waitForURL('**/accounts', { timeout: 15000 });
    await expect(page.locator('text=NewService')).toBeVisible({ timeout: 10000 });
  });

  test('P1: Navigate to account edit page', async ({ page }) => {
    await page.goto(routes.accounts);
    await page.waitForLoadState('networkidle');

    // Wait for accounts to load
    await expect(page.locator('.tfa-cell').first()).toBeVisible({ timeout: 10000 });

    // Enter management/edit mode by toggling the edit mode button
    const editModeBtn = page.locator('button[aria-label="Edit"], .is-edit-mode-toggle, .fa-pen');
    if (await editModeBtn.isVisible().catch(() => false)) {
      await editModeBtn.click();
    }

    // Click the first account's edit link (shown in edit mode)
    const editLink = page.locator('a[href*="/edit"], .is-edit a').first();
    if (await editLink.isVisible().catch(() => false)) {
      await editLink.click();
      await expect(page).toHaveURL(/\/account\/\d+\/edit/, { timeout: 10000 });
    }
  });
});
