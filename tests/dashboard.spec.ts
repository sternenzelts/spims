import { test, expect } from '@playwright/test';
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { HomePage } from './pages/HomePage';

/** Shared helper: authenticate and wait for the dashboard. */
async function authenticateAndReachDashboard(page: import('@playwright/test').Page) {
  const username = process.env.TEST_USERNAME ?? '';
  const password = process.env.TEST_PASSWORD ?? '';

  const login = new LoginPage(page);
  await login.goto();
  await login.login(username, password);
  await page.waitForURL(/\/quality-agent/, { timeout: 20_000 });
}

// ---------------------------------------------------------------------------
// s7 – Dashboard page loads and shows key elements after authentication
// Assumption: the app exits any 'Loading…' state within the Playwright default
// timeout (30 s). If it remains in loading indefinitely that is treated as a
// failure.
// ---------------------------------------------------------------------------
test('[s7] Dashboard page loads and shows key elements after authentication', async ({ page }) => {
  await authenticateAndReachDashboard(page);

  const dashboard = new DashboardPage(page);
  await dashboard.assertLoaded();

  // Ensure no permanent loading spinner
  const loadingText = page.getByText('Loading…');
  // Loading text may flicker briefly; we assert it is NOT persistently visible
  // by waiting a short moment and then checking
  await page.waitForTimeout(2_000);
  const stillLoading = await loadingText.isVisible().catch(() => false);
  expect(stillLoading, 'Page should not remain in a permanent Loading… state').toBe(false);
});

// ---------------------------------------------------------------------------
// s11 – Logout from the dashboard redirects to the login or home page
// ---------------------------------------------------------------------------
test('[s11] Logout from the dashboard redirects to login or home page', async ({ page }) => {
  await authenticateAndReachDashboard(page);

  const dashboard = new DashboardPage(page);
  await expect(dashboard.logoutButton).toBeVisible();
  await dashboard.logoutButton.click();

  // Accept redirect to either /login or /
  await page.waitForURL(url => {
    const p = url.pathname;
    return p === '/login' || p === '/' || p === '';
  }, { timeout: 15_000 });

  const currentUrl = page.url();
  const isOnLoginOrHome = /\/login/.test(currentUrl) || /\/$/.test(currentUrl);
  expect(isOnLoginOrHome).toBe(true);

  // Verify the dashboard is no longer accessible without re-authentication
  await page.goto('/quality-agent');
  // Should be redirected away from /quality-agent (to login or home)
  await page.waitForURL(url => !url.pathname.startsWith('/quality-agent'), { timeout: 10_000 });
  await expect(page).not.toHaveURL(/\/quality-agent/);

  // Home page hero or login page heading should be visible
  const home = new HomePage(page);
  const login = new LoginPage(page);
  const heroVisible = await home.heroHeading.isVisible().catch(() => false);
  const loginVisible = await login.headingAnalytics.isVisible().catch(() => false);
  expect(heroVisible || loginVisible, 'Expected home or login page content after logout').toBe(true);
});
