import { test as base, Page, expect } from '@playwright/test';
import { testUsers, routes, sel } from './test-data.fixture';

type AuthFixture = {
  loginAs: (email: string, password: string) => Promise<void>;
  loginAsAdmin: () => Promise<void>;
  loginAsUser: () => Promise<void>;
  loginAsEncrypted: () => Promise<void>;
  loginAsLockedEncrypted: () => Promise<void>;
  loginAsConflict: () => Promise<void>;
  loginAsBackup: () => Promise<void>;
  logout: () => Promise<void>;
};

async function performLogin(page: Page, email: string, password: string): Promise<void> {
  const consoleMessages: string[] = [];
  const failedRequests: string[] = [];
  const assetResponses: string[] = [];

  const consoleHandler = (message) => {
    consoleMessages.push(`[${message.type()}] ${message.text()}`);
  };

  const requestFailedHandler = (request) => {
    failedRequests.push(`${request.method()} ${request.url()} => ${request.failure()?.errorText ?? 'unknown failure'}`);
  };

  const responseHandler = async (response) => {
    const url = response.url();
    const status = response.status();
    const shouldCapture = url.includes('/build/') || url.endsWith('/login') || status >= 400 || url.includes('/api/v1/') || url.includes('/user/') || url.includes('/refresh-csrf');

    if (!shouldCapture) return;

    let bodySnippet = '';
    if (status >= 400) {
      try {
        const text = await response.text();
        bodySnippet = ` body=${text.slice(0, 500)}`;
      } catch {
        bodySnippet = ' body=<unavailable>';
      }
    }
    assetResponses.push(`${status} ${url} (${response.request().resourceType()})${bodySnippet}`);
  };

  page.on('console', consoleHandler);
  page.on('requestfailed', requestFailedHandler);
  page.on('response', responseHandler);

  await page.goto(routes.login);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.locator('#app').waitFor({ state: 'attached', timeout: 15000 });
  await Promise.race([
    page.getByRole('heading', { name: 'Login' }).waitFor({ state: 'visible', timeout: 15000 }),
    page.getByRole('heading', { name: 'Webauthn login' }).waitFor({ state: 'visible', timeout: 15000 }),
    page.getByRole('heading', { name: 'SSO login' }).waitFor({ state: 'visible', timeout: 15000 }),
  ]).catch(() => {});

  const legacyForm = page.locator(sel.legacyLoginForm);
  const webauthnForm = page.locator(sel.webauthnLoginForm);
  const ssoForm = page.locator('#lnkSsoDocs');
  const legacyLink = page.locator(sel.switchToLegacy);

  const detectLoginSurface = async () => {
    const deadline = Date.now() + 15000;

    while (Date.now() < deadline) {
      if (await legacyForm.isVisible().catch(() => false)) return 'legacy';
      if (await legacyLink.isVisible().catch(() => false)) return 'switch-to-legacy';
      if (await webauthnForm.isVisible().catch(() => false)) return 'webauthn';
      if (await ssoForm.isVisible().catch(() => false)) return 'sso';

      await page.waitForTimeout(250);
    }

    return 'unknown';
  };

  const loginSurface = await detectLoginSurface();

  if (loginSurface === 'switch-to-legacy') {
    await legacyLink.click();
    await legacyForm.waitFor({ state: 'visible', timeout: 15000 });
  }

  if (loginSurface === 'sso') {
    page.off('console', consoleHandler);
    page.off('requestfailed', requestFailedHandler);
    page.off('response', responseHandler);
    throw new Error(`Login page is in SSO-only mode for ${email} at ${page.url()}`);
  }

  if (loginSurface === 'unknown') {
    const bodyHtml = await page.locator('body').evaluate((node) => node.innerHTML).catch(() => 'unable to read body html');
    const activeLoginForm = await page.evaluate(() => {
      const storage = window.localStorage;
      const keys = Object.keys(storage).filter((key) => key.endsWith('activeLoginForm'));
      return keys.map((key) => ({ key, value: storage.getItem(key) }));
    }).catch(() => []);
    const headings = await page.getByRole('heading').allTextContents().catch(() => []);
    const forms = await page.locator('form').evaluateAll((nodes) => nodes.map((node) => ({ id: node.id, className: node.className }))).catch(() => []);
    const scripts = await page.evaluate(() => Array.from(document.scripts).map((script) => ({ src: script.src, type: script.type }))).catch(() => []);
    const readyState = await page.evaluate(() => document.readyState).catch(() => 'unknown');

    page.off('console', consoleHandler);
    page.off('requestfailed', requestFailedHandler);
    page.off('response', responseHandler);

    throw new Error(
      `Unable to detect login surface for ${email} at ${page.url()}. ` +
      `readyState=${readyState} ` +
      `activeLoginForm=${JSON.stringify(activeLoginForm)} ` +
      `headings=${JSON.stringify(headings)} ` +
      `forms=${JSON.stringify(forms)} ` +
      `scripts=${JSON.stringify(scripts)} ` +
      `assetResponses=${JSON.stringify(assetResponses.slice(-20))} ` +
      `failedRequests=${JSON.stringify(failedRequests.slice(-20))} ` +
      `consoleMessages=${JSON.stringify(consoleMessages.slice(-20))} ` +
      `bodyHtml=${String(bodyHtml).slice(0, 2000)}`
    );
  }

  await legacyForm.waitFor({ state: 'visible', timeout: 15000 });

  // Fill credentials
  await legacyForm.locator(sel.emailInput).fill(email);
  await legacyForm.locator(sel.passwordInput).fill(password);

  // Submit
  await legacyForm.locator(sel.signInButton).click();

  try {
    // Wait for SPA navigation (client-side routing)
    await Promise.race([
      page.waitForURL('**/accounts', { timeout: 15000 }),
      page.waitForURL('**/start', { timeout: 15000 }),
      page.waitForURL('**/setup-encryption', { timeout: 15000 }),
      page.waitForURL('**/unlock-vault', { timeout: 15000 }),
    ]);
  } catch (error) {
    const bodyHtml = await page.locator('body').evaluate((node) => node.innerHTML).catch(() => 'unable to read body html');
    const errorText = await page.locator('.error-message').allTextContents().catch(() => []);
    const loginResponses = assetResponses.slice(-30);

    page.off('console', consoleHandler);
    page.off('requestfailed', requestFailedHandler);
    page.off('response', responseHandler);

    throw new Error(
      `Login navigation failed for ${email} at ${page.url()}. ` +
      `errorText=${JSON.stringify(errorText)} ` +
      `loginResponses=${JSON.stringify(loginResponses)} ` +
      `failedRequests=${JSON.stringify(failedRequests.slice(-20))} ` +
      `consoleMessages=${JSON.stringify(consoleMessages.slice(-20))} ` +
      `bodyHtml=${String(bodyHtml).slice(0, 2000)}`,
      { cause: error }
    );
  }

  page.off('console', consoleHandler);
  page.off('requestfailed', requestFailedHandler);
  page.off('response', responseHandler);
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
  loginAsLockedEncrypted: async ({ page }, use) => {
    await performLogin(page, testUsers.lockedEncrypted.email, testUsers.lockedEncrypted.password);
    await use();
  },
  loginAsConflict: async ({ page }, use) => {
    await performLogin(page, testUsers.conflict.email, testUsers.conflict.password);
    await use();
  },
  loginAsBackup: async ({ page }, use) => {
    await performLogin(page, testUsers.backup.email, testUsers.backup.password);
    await use();
  },
  logout: async ({ page }, use) => {
    await use(async () => {
      await performLogout(page);
    });
  },
});

export { expect };
