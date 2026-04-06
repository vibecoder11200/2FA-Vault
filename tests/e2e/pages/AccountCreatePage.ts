import { Page, Locator } from '@playwright/test';
import { routes, sel } from '../fixtures/test-data.fixture';

export class AccountCreatePage {
  readonly page: Page;
  readonly serviceInput: Locator;
  readonly accountInput: Locator;
  readonly secretInput: Locator;
  readonly createButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.serviceInput = page.locator(sel.serviceInput);
    this.accountInput = page.locator(sel.accountInput);
    this.secretInput = page.locator(sel.secretInput);
    this.createButton = page.locator('#btnCreate');
  }

  async goto() {
    await this.page.goto(routes.createAccount);
    // Wait for SPA to mount and onMounted to set showAdvancedForm=true
    await this.page.waitForLoadState('networkidle');
    await this.serviceInput.waitFor({ state: 'visible', timeout: 10000 });
  }

  async selectTotp() {
    // FormToggle renders <button role="radio"> elements (not actual <input type="radio">)
    // The hidden input has class="is-hidden", so click the visible button
    await this.page.getByRole('radio', { name: 'TOTP' }).click();
    // Wait for the secret field to appear
    await this.secretInput.waitFor({ state: 'visible', timeout: 5000 });
  }

  async fillAccount(data: {
    service?: string;
    account?: string;
    secret?: string;
    otpType?: string;
  }) {
    if (data.service) await this.serviceInput.fill(data.service);
    if (data.account) await this.accountInput.fill(data.account);
    // Select OTP type before filling secret (secret field is conditional)
    if (data.secret || data.otpType) {
      await this.selectTotp();
    }
    if (data.secret) await this.secretInput.fill(data.secret);
  }

  async submit() {
    // The Create button is in the VueFooter, not the form
    await this.createButton.click();
  }
}
