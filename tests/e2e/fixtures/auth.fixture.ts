import { test as base, Page, expect } from '@playwright/test';
import { testUsers, routes, sel } from './test-data.fixture';

type AuthFixture = {
  loginAs: (email: string, password: string) => Promise<void>;
  loginAsAdmin: () => Promise<void>;
  loginAsUser: () => Promise<void>;
  loginAsEncrypted: () => Promise<void>;
  logout: () => Promise<void>;
};

async function performLogin(page: Page, email: string, password: string): Promise<void> {
  await page.goto(routes.login);

  // Wait for SPA mount - legacy login form
  const form = page.locator(sel.legacyLoginForm);
  await form.waitFor({ state: 'visible', timeout: 15000 });

  // If only WebAuthn form is showing, switch to legacy
  const legacyLink = page.locator(sel.switchToLegacy);
  if (await legacyLink.isVisible()) {
    await legacyLink.click();
    await form.waitFor({ state: 'visible' });
  }

  // Fill credentials
  await form.locator(sel.emailInput).fill(email);
  await form.locator(sel.passwordInput).fill(password);

  // Submit
  await form.locator(sel.signInButton).click();

  // Wait for SPA navigation (client-side routing)
  await Promise.race([
    page.waitForURL('**/accounts', { timeout: 15000 }),
    page.waitForURL('**/start', { timeout: 15000 }),
    page.waitForURL('**/setup-encryption', { timeout: 15000 }),
    page.waitForURL('**/unlock-vault', { timeout: 15000 }),
  ]);
}

async function performLogout(page: Page): Promise<void> {
  const logoutBtn = page.locator(sel.signOutButton);
  // The footer menu may need to be opened first on mobile
  const menuToggle = page.locator('.footer.main .menu-toggle, button[aria-label="menu"]');
  if (await menuToggle.isVisible().catch(() => false)) {
    await menuToggle.click();
  }
  await logoutBtn.click();
  await page.waitForURL('**/login', { timeout: 10000 });
}

export const test = base.extend<AuthFixture>({
  loginAs: async ({ page }, use) => {
    await use(async (email: string, password: string) => {
      await performLogin(page, email, password);
    });
  },
  loginAsAdmin: async ({ page }, use) => {
    await performLogin(page, testUsers.admin.email, testUsers.admin.password);
    await use();
  },
  loginAsUser: async ({ page }, use) => {
    await performLogin(page, testUsers.user.email, testUsers.user.password);
    await use();
  },
  loginAsEncrypted: async ({ page }, use) => {
    await performLogin(page, testUsers.encrypted.email, testUsers.encrypted.password);
    await use();
  },
  logout: async ({ page }, use) => {
    await use(async () => {
      await performLogout(page);
    });
  },
});

export { expect };
