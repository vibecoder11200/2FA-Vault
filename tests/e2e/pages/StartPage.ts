import { Page, Locator } from '@playwright/test';
import { routes, sel } from '../fixtures/test-data.fixture';

export class StartPage {
  readonly page: Page;
  readonly importLink: Locator;

  constructor(page: Page) {
    this.page = page;
    this.importLink = page.locator(sel.importButton);
  }

  async goto() {
    await this.page.goto(routes.start);
    await this.page.waitForLoadState('networkidle');
  }
}
