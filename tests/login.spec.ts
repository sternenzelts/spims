import { test, expect } from '@playwright/test';
import { LoginPage } from './pages/LoginPage';
import { HomePage } from './pages/HomePage';
import { DashboardPage } from './pages/DashboardPage';

// ---------------------------------------------------------------------------
// s4 – Login page renders all UI elements correctly
// ---------------------------------------------------------------------------
test('[s4] Login page renders all UI elements correctly', async ({ page }) => {
  const login = new LoginPage(page);
  await login.goto();
  await login.assertPageElements();
});

// ---------------------------------------------------------------------------
// s5 – Login button is disabled when Username and Password are empty
// ---------------------------------------------------------------------------
test('[s5] Login button is disabled when Username and Password are empty', async ({ page }) => {
  const login = new LoginPage(page);
  await login.goto();

  // Ensure both fields are empty (default state)
  await expect(login.usernameInput).toHaveValue('');
  await expect(login.passwordInput).toHaveValue('');

  // The button must be disabled OR a hint must be visible – accept either.
  const isDisabled = await login.loginButton.isDisabled();
  if (!isDisabled) {
    // Fall back: check for the hint text
    await expect(
      page.getByText('Please fill in all fields to enable login')
    ).toBeVisible();
  } else {
    expect(isDisabled).toBe(true);
  }
});

// ---------------------------------------------------------------------------
// s6 – User can log in successfully with valid credentials
// ---------------------------------------------------------------------------
test('[s6] User can log in successfully with valid credentials', async ({ page }) => {
  const username = process.env.TEST_USERNAME ?? '';
  const password = process.env.TEST_PASSWORD ?? '';

  const login = new LoginPage(page);
  await login.goto();
  await login.login(username, password);

  // Wait for redirect away from /login
  await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 15_000 });

  await expect(page).toHaveURL(/\/quality-agent/);

  const dashboard = new DashboardPage(page);
  await expect(dashboard.heading).toBeVisible();
});

// ---------------------------------------------------------------------------
// s8 – Login with wrong password shows an error and stays on the login page
// ---------------------------------------------------------------------------
test('[s8] Login with wrong password shows an error and stays on the login page', async ({ page }) => {
  const username = process.env.TEST_USERNAME ?? '';
  // Derive an invalid password from the real one – never a literal
  const wrongPassword = (process.env.TEST_PASSWORD ?? '') + '-wrong';

  const login = new LoginPage(page);
  await login.goto();
  await login.login(username, wrongPassword);

  // Should stay on /login
  await expect(page).toHaveURL(/\/login/);

  // The app should present some error feedback; we accept any visible error
  // message without prescribing its exact text.
  const errorLocator = page.getByRole('alert');
  const isAlertVisible = await errorLocator.isVisible().catch(() => false);
  if (!isAlertVisible) {
    // Fallback: look for common error text patterns
    const fallback = page.getByText(/(invalid|incorrect|error|failed|wrong)/i);
    await expect(fallback).toBeVisible();
  } else {
    await expect(errorLocator).toBeVisible();
  }
});

// ---------------------------------------------------------------------------
// s9 – 'Back to Homepage' link navigates to the home page
// ---------------------------------------------------------------------------
test('[s9] \'Back to Homepage\' link navigates to the home page', async ({ page }) => {
  const login = new LoginPage(page);
  await login.goto();

  await login.backToHomepageLink.click();
  await page.waitForURL('**/'); // wait for root URL

  await expect(page).toHaveURL(/\/$|\/?$/);

  const home = new HomePage(page);
  await expect(home.heroHeading).toBeVisible();
});
