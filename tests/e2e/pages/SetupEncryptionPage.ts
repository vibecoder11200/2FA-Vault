import { Page, Locator } from '@playwright/test';
import { routes } from '../fixtures/test-data.fixture';

export class SetupEncryptionPage {
  readonly page: Page;
  readonly passwordInputs: Locator;
  readonly checkbox: Locator;
  readonly submitButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.passwordInputs = page.locator('input[type="password"]');
    this.checkbox = page.locator('input[type="checkbox"]');
    this.submitButton = page.locator('button[type="submit"]');
  }

  async goto() {
    await this.page.goto(routes.setupEncryption);
    await this.page.waitForLoadState('networkidle');
  }

  async fillMasterPassword(password: string) {
    const inputs = this.passwordInputs;
    const count = await inputs.count();
    if (count >= 1) await inputs.nth(0).fill(password);
    if (count >= 2) await inputs.nth(1).fill(password);
  }

  async acknowledgeRisk() {
    const cb = this.checkbox;
    if (!(await cb.isChecked())) {
      await cb.check();
    }
  }

  async submit() {
    await this.submitButton.click();
  }
}
