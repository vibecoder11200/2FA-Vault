import { Page, Locator } from '@playwright/test';
import { sel, routes } from '../fixtures/test-data.fixture';

export class LoginPage {
  readonly page: Page;
  readonly form: Locator;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly signInButton: Locator;
  readonly registerLink: Locator;
  readonly resetPasswordLink: Locator;

  constructor(page: Page) {
    this.page = page;
    this.form = page.locator(sel.legacyLoginForm);
    this.emailInput = this.form.locator(sel.emailInput);
    this.passwordInput = this.form.locator(sel.passwordInput);
    this.signInButton = this.form.locator(sel.signInButton);
    this.registerLink = page.locator(sel.registerLink);
    this.resetPasswordLink = page.locator(sel.resetPasswordLink);
  }

  async goto() {
    await this.page.goto(routes.login);
    await this.ensureLegacyForm();
  }

  async ensureLegacyForm() {
    const form = this.form;
    await form.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    if (!(await form.isVisible())) {
      const link = this.page.locator(sel.switchToLegacy);
      if (await link.isVisible()) {
        await link.click();
        await form.waitFor({ state: 'visible' });
      }
    }
  }

  async login(email: string, password: string) {
    await this.ensureLegacyForm();
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.signInButton.click();
  }

  async waitForRedirect() {
    return Promise.race([
      this.page.waitForURL('**/accounts', { timeout: 15000 }),
      this.page.waitForURL('**/start', { timeout: 15000 }),
      this.page.waitForURL('**/setup-encryption', { timeout: 15000 }),
      this.page.waitForURL('**/unlock-vault', { timeout: 15000 }),
    ]);
  }
}
