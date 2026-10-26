import { type Page, type Locator, expect } from '@playwright/test';

export class DashboardPage {
  readonly page: Page;
  readonly heading: Locator;
  readonly navigation: Locator;
  readonly logoutButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: 'Quality Agent' });
    this.navigation = page.getByRole('navigation');
    this.logoutButton = page.getByRole('button', { name: 'Logout' });
  }

  async assertLoaded() {
    await expect(this.page).toHaveURL(/\/quality-agent/);
    // Assumption: after authentication the page settles and shows a 'Quality Agent'
    // heading. If the app keeps a 'Loading…' spinner indefinitely that would be a
    // bug; we wait up to the default timeout for the heading to appear.
    await expect(this.heading).toBeVisible();
    await expect(this.navigation).toBeVisible();
    await expect(this.logoutButton).toBeVisible();
  }
}
