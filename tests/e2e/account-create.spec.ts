import { test, expect } from './fixtures/auth.fixture';
import { testUsers, routes, sel } from './fixtures/test-data.fixture';
import { AccountCreatePage } from './pages/AccountCreatePage';

test.describe('Create 2FA Account', () => {
  test.beforeEach(async ({ loginAsAdmin }) => {
    // loginAsAdmin fixture handles authentication
  });

  test('P0: Navigate to create account page shows form', async ({ page }) => {
    const createPage = new AccountCreatePage(page);
    await createPage.goto();

    // The advanced form shows after onMounted sets showAdvancedForm=true
    await expect(createPage.serviceInput).toBeVisible({ timeout: 10000 });
    await expect(createPage.accountInput).toBeVisible();
  });

  test('P0: Create a TOTP account via manual entry', async ({ page }) => {
    const createPage = new AccountCreatePage(page);
    await createPage.goto();

    await createPage.fillAccount({
      service: 'TestService',
      account: 'user@test.com',
      secret: 'A4GRFTVVRBGY7UIW',
    });

    await createPage.submit();

    // Should redirect back to accounts
    await page.waitForURL('**/accounts', { timeout: 15000 });
    await expect(page).toHaveURL(/\/accounts/);

    // New account should appear in the list
    await page.waitForTimeout(1000);
    await expect(page.locator('text=TestService')).toBeVisible({ timeout: 10000 });
  });

  test('P0: Create account with empty secret shows error', async ({ page }) => {
    const createPage = new AccountCreatePage(page);
    await createPage.goto();

    // Select OTP type first (required for secret field to appear)
    await createPage.selectTotp();

    // Fill service and account but leave secret empty
    await createPage.serviceInput.fill('EmptySecret');
    await createPage.accountInput.fill('empty@test.com');

    await createPage.submit();

    // Should show validation error and stay on create page
    await page.waitForTimeout(2000);
    await expect(page).toHaveURL(/\/account\/create/);
  });
});
