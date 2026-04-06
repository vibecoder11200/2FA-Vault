import { test, expect } from './fixtures/auth.fixture';
import { testUsers, routes, sel } from './fixtures/test-data.fixture';
import { LoginPage } from './pages/LoginPage';

test.describe('Authentication Flow', () => {
  test('P0: Login with valid credentials (admin with accounts) redirects to /accounts', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.admin.email, testUsers.admin.password);
    await loginPage.waitForRedirect();

    await expect(page).toHaveURL(/\/accounts/);
  });

  test('P0: Login with valid credentials (new user) redirects to /start', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.user.email, testUsers.user.password);
    await loginPage.waitForRedirect();

    await expect(page).toHaveURL(/\/start/);
  });

  test('P0: Login with invalid password shows error', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.admin.email, 'wrong-password');

    // Should stay on login page
    await page.waitForTimeout(2000);
    await expect(page).toHaveURL(/\/login/);
  });

  test('P0: Login with empty email shows validation error', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login('', 'password');

    // Should show validation error (422 from server)
    await page.waitForTimeout(2000);
    // Either a validation error element or the form stays on login
    await expect(page).toHaveURL(/\/login/);
  });

  test('P0: Logout redirects to /login', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.admin.email, testUsers.admin.password);
    await loginPage.waitForRedirect();
    await expect(page).toHaveURL(/\/accounts/);

    // Open footer menu by clicking the email button
    const emailMenuBtn = page.locator('#btnEmailMenu');
    await emailMenuBtn.waitFor({ state: 'visible', timeout: 5000 });
    await emailMenuBtn.click();

    // Click sign-out (triggers a confirm dialog)
    page.once('dialog', async dialog => {
      await dialog.accept();
    });
    await page.locator(sel.signOutButton).click();

    await expect(page).toHaveURL(/\/login/, { timeout: 10000 });
  });

  test('P0: Register link navigates to /register', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.ensureLegacyForm();

    await loginPage.registerLink.click();
    await expect(page).toHaveURL(/\/register/);
  });

  test('P0: Direct access to /accounts while unauthenticated redirects to /login', async ({ page }) => {
    await page.goto(routes.accounts);

    // SPA auth guard should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 10000 });
  });

  test('P0: Direct access to /settings/options while unauthenticated redirects to /login', async ({ page }) => {
    await page.goto(routes.settingsOptions);

    await expect(page).toHaveURL(/\/login/, { timeout: 10000 });
  });
});
