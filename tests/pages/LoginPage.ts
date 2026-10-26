import { type Page, type Locator, expect } from '@playwright/test';

export class LoginPage {
  readonly page: Page;
  readonly usernameInput: Locator;
  readonly passwordInput: Locator;
  readonly loginButton: Locator;
  readonly backToHomepageLink: Locator;
  readonly headingAnalytics: Locator;
  readonly headingLoginAccount: Locator;

  constructor(page: Page) {
    this.page = page;
    this.usernameInput = page.getByLabel('Username');
    this.passwordInput = page.getByLabel('Password');
    this.loginButton = page.getByRole('button', { name: 'Login' });
    this.backToHomepageLink = page.getByRole('link', { name: 'Back to Homepage' });
    this.headingAnalytics = page.getByRole('heading', { name: 'KORA TEST ANALYTICS' });
    this.headingLoginAccount = page.getByRole('heading', { name: 'Login to your account' });
  }

  async goto() {
    await this.page.goto('/login');
  }

  async login(username: string, password: string) {
    await this.usernameInput.fill(username);
    await this.passwordInput.fill(password);
    await this.loginButton.click();
  }

  async assertPageElements() {
    await expect(this.headingAnalytics).toBeVisible();
    await expect(this.headingLoginAccount).toBeVisible();
    await expect(this.usernameInput).toBeVisible();
    await expect(this.passwordInput).toBeVisible();
    await expect(this.loginButton).toBeVisible();
    await expect(this.backToHomepageLink).toBeVisible();
  }
}
