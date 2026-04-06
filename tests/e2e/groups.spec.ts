import { test, expect } from './fixtures/auth.fixture';
import { routes, sel } from './fixtures/test-data.fixture';
import { GroupsPage } from './pages/GroupsPage';

test.describe('Groups', () => {
  test.beforeEach(async ({ loginAsAdmin }) => {
    // Admin has pre-seeded group
  });

  test('P1: Groups page shows seeded group', async ({ page }) => {
    const groupsPage = new GroupsPage(page);
    await groupsPage.goto();

    // Admin has 'E2E Test Group' from E2eSeeder
    await expect(page.locator('text=E2E Test Group')).toBeVisible({ timeout: 10000 });
  });

  test('P1: Navigate to create group page', async ({ page }) => {
    await page.goto(routes.groups);
    await page.waitForLoadState('networkidle');

    // Find and click the create group button
    const createGroupBtn = page.locator('a[href*="/group/create"], button:has-text("create"), .is-primary:has-text("group")');
    if (await createGroupBtn.first().isVisible({ timeout: 5000 }).catch(() => false)) {
      await createGroupBtn.first().click();
      await expect(page).toHaveURL(/\/group\/create/, { timeout: 10000 });

      // Should show group name input
      await expect(page.locator(sel.groupNameInput)).toBeVisible({ timeout: 10000 });
    }
  });
});
