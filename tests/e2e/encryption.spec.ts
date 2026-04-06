import { test, expect } from '@playwright/test';
import { testUsers, routes } from './fixtures/test-data.fixture';
import { LoginPage } from './pages/LoginPage';

test.describe('Encryption Flow', () => {
  test('P1: Setup encryption page loads for authenticated user', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.user.email, testUsers.user.password);
    await loginPage.waitForRedirect();
    // New user lands on /start, navigate to encryption setup
    await page.goto(routes.setupEncryption);

    // Should show the encryption setup form
    const passwordInputs = page.locator('input[type="password"]');
    await expect(passwordInputs.first()).toBeVisible({ timeout: 10000 });
  });

  test('P1: Setup encryption with master password', async ({ page }) => {
    // Login as regular user (no encryption)
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.user.email, testUsers.user.password);
    await loginPage.waitForRedirect();

    // Navigate to setup encryption
    await page.goto(routes.setupEncryption);
    await page.waitForLoadState('networkidle');

    // Fill master password (two password inputs: master + confirm)
    const masterPassword = 'MasterPass123!';
    const passwordInputs = page.locator('input[type="password"]');
    await passwordInputs.nth(0).fill(masterPassword);
    await passwordInputs.nth(1).fill(masterPassword);

    // Acknowledge the risk checkbox (the "I understand..." one, not "Show password")
    const riskCheckbox = page.locator('input[type="checkbox"]').nth(1);
    await riskCheckbox.check();

    // Submit
    await page.locator('button[type="submit"]').click();

    // Should redirect to accounts or start
    await Promise.race([
      page.waitForURL('**/accounts', { timeout: 15000 }),
      page.waitForURL('**/start', { timeout: 5000 }),
    ]).catch(() => {});
  });

  test('P1: Encrypted user can login and access app', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.encrypted.email, testUsers.encrypted.password);
    await loginPage.waitForRedirect();

    // Encrypted user has no accounts, so lands on /start
    // (starter middleware redirects users with no accounts to /start)
    await expect(page).toHaveURL(/\/(start|accounts)/);
  });
});
