import { Page, Locator } from '@playwright/test';
import { routes, sel } from '../fixtures/test-data.fixture';

export class GroupsPage {
  readonly page: Page;
  readonly groupItems: Locator;
  readonly signOutButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.groupItems = page.locator('.group-item, .group');
    this.signOutButton = page.locator(sel.signOutButton);
  }

  async goto() {
    await this.page.goto(routes.groups);
    await this.page.waitForLoadState('networkidle');
  }
}
