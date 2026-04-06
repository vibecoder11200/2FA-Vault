import { Page, Locator } from '@playwright/test';
import { routes } from '../fixtures/test-data.fixture';

export class AccountsPage {
  readonly page: Page;
  readonly accountItems: Locator;
  readonly signOutButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.accountItems = page.locator('.account-item, .twofaccount');
    this.signOutButton = page.locator('#lnkSignOut');
  }

  async goto() {
    await this.page.goto(routes.accounts);
    await this.page.waitForLoadState('networkidle');
  }

  async getAccountNames(): Promise<string[]> {
    const names: string[] = [];
    const services = this.page.locator('.account-service, .service-name');
    const count = await services.count();
    for (let i = 0; i < count; i++) {
      const text = await services.nth(i).textContent();
      if (text) names.push(text.trim());
    }
    return names;
  }

  async logout() {
    const menuToggle = this.page.locator('.footer.main .menu-toggle');
    if (await menuToggle.isVisible().catch(() => false)) {
      await menuToggle.click();
    }
    await this.signOutButton.click();
    await this.page.waitForURL('**/login', { timeout: 10000 });
  }
}
