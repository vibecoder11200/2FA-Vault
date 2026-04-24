import { test, expect } from './fixtures/auth.fixture';
import { testUsers, routes } from './fixtures/test-data.fixture';
import { LoginPage } from './pages/LoginPage';
import { SetupEncryptionPage } from './pages/SetupEncryptionPage';

const masterPassword = 'MasterPass123!';

test.describe('Encryption Flow', () => {
  test('P1: Setup encryption page loads for unencrypted authenticated user', async ({ page, loginAsUser }) => {
    await page.goto(routes.setupEncryption);

    const setupPage = new SetupEncryptionPage(page);
    await expect(setupPage.passwordInputs.first()).toBeVisible({ timeout: 10000 });
  });

  test('P1: Setup encryption submit stays disabled without acknowledgment', async ({ page, loginAsUser }) => {
    const setupPage = new SetupEncryptionPage(page);
    await setupPage.goto();

    await setupPage.fillMasterPassword(masterPassword);

    await expect(setupPage.submitButton).toBeDisabled();
    await expect(page).toHaveURL(/\/setup-encryption/, { timeout: 10000 });
  });

  test('P1: Locked encrypted user is authenticated and then route-gated from backup to unlock-vault', async ({ page, loginAsLockedEncrypted }) => {
    await expect(page).toHaveURL(/\/(start|accounts|unlock-vault)/, { timeout: 15000 });

    await page.goto(routes.settingsBackup);
    await expect(page).toHaveURL(/\/unlock-vault/, { timeout: 10000 });
  });

  test('P1: Locked encrypted user cannot access accounts directly', async ({ page, loginAsLockedEncrypted }) => {
    await page.goto(routes.accounts);
    await expect(page).toHaveURL(/\/(unlock-vault|start)/, { timeout: 10000 });
  });

  test('P1: Locked encrypted user remains on unlock-vault with invalid unlock password', async ({ page, loginAsLockedEncrypted }) => {
    await page.goto(routes.unlockVault);

    const passwordInput = page.getByLabel('Master Password', { exact: true });
    await expect(passwordInput).toBeVisible({ timeout: 10000 });
    await passwordInput.fill('password');
    await page.getByRole('button', { name: /Unlock Vault/ }).click();

    await expect(page).toHaveURL(/\/unlock-vault/, { timeout: 15000 });
    await expect(page.getByText('Invalid master password. Please try again.')).toBeVisible({ timeout: 10000 });
  });

  test('P1: Encrypted unlocked user can access backup page directly', async ({ page, loginAsBackup }) => {
    await page.goto(routes.settingsBackup);
    await expect(page).toHaveURL(/\/settings\/backup/, { timeout: 10000 });
  });

  test('P1: Unencrypted user navigating to unlock-vault is redirected to accounts/start', async ({ page, loginAsUser }) => {
    await page.goto(routes.unlockVault);
    await expect(page).toHaveURL(/\/(accounts|start)/, { timeout: 10000 });
  });

  test('P1: Encrypted user login is redirected to unlock-vault when vault is locked', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(testUsers.backup.email, testUsers.backup.password);
    await loginPage.waitForRedirect();

    await expect(page).toHaveURL(/\/unlock-vault/, { timeout: 15000 });
  });
});
