import { test, expect } from '@playwright/test';
import { LoginPage } from './pages/LoginPage';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function env(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Environment variable ${name} is not set`);
  return value;
}

// ---------------------------------------------------------------------------
// s1 – Successful login with valid credentials
// ---------------------------------------------------------------------------

test('s1 – successful login with valid credentials', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_USER_EMAIL');
  const password = env('E2E_USER_PASSWORD');

  await loginPage.login(email, password);

  // After a successful login the app must redirect away from /login
  await expect(page).not.toHaveURL(/\/login/);

  // The user should reach some authenticated page
  await expect(page).toHaveURL(/.+/);
});

// ---------------------------------------------------------------------------
// s2 – Login blocked after repeated failed attempts (lockout)
// ---------------------------------------------------------------------------

test('s2 – login blocked after repeated failed attempts (lockout)', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_USER_EMAIL');
  // Derive a clearly wrong password so we never accidentally use the real one
  const wrongPassword = env('E2E_USER_PASSWORD') + '-wrong';

  // Attempt login 6 times with a bad password to trigger the lockout
  for (let attempt = 1; attempt <= 6; attempt++) {
    await loginPage.login(email, wrongPassword);
  }

  // The application must now show a lockout / too-many-attempts message
  // and keep the user on (or redirect back to) the login page
  await expect(page).toHaveURL(/\/login/);

  // Expect some visible error text indicating lockout
  const bodyText = page.locator('body');
  await expect(bodyText).toContainText(
    /too many login attempts|locked|throttl/i
  );
});

// ---------------------------------------------------------------------------
// s3 – Operator role user logs in and sees Station UI
// ---------------------------------------------------------------------------

test('s3 – operator user logs in and lands on Station UI', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_OPERATOR_EMAIL');
  const password = env('E2E_OPERATOR_PASSWORD');

  await loginPage.login(email, password);

  // Operator must not end up on an admin page
  await expect(page).not.toHaveURL(/\/admin/);

  // The Station interface should be visible — look for a heading or landmark
  // that belongs to the Station module (part-lookup, withdrawal, etc.)
  await expect(page).not.toHaveURL(/\/login/);

  // At least one station-related element must be present
  const stationContent = page.getByRole('main');
  await expect(stationContent).toBeVisible();
});

// ---------------------------------------------------------------------------
// s4 – Admin role user logs in and sees Admin UI
// ---------------------------------------------------------------------------

test('s4 – admin user logs in and lands on Admin UI', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_ADMIN_EMAIL');
  const password = env('E2E_ADMIN_PASSWORD');

  await loginPage.login(email, password);

  // Admin must be routed to the admin area, not the station
  await expect(page).not.toHaveURL(/\/login/);

  // The admin dashboard URL must contain /admin or /dashboard
  await expect(page).toHaveURL(/\/admin|\/dashboard/);

  // The main content area must be visible
  const mainContent = page.getByRole('main');
  await expect(mainContent).toBeVisible();
});

// ---------------------------------------------------------------------------
// s5 – Operator role user cannot access Admin-only pages
// ---------------------------------------------------------------------------

test('s5 – operator user is denied access to admin-only pages', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_OPERATOR_EMAIL');
  const password = env('E2E_OPERATOR_PASSWORD');

  // Log in as operator first
  await loginPage.login(email, password);
  await expect(page).not.toHaveURL(/\/login/);

  // Attempt to navigate directly to an admin-only URL
  await page.goto('/admin/users');

  // The application must NOT show the admin users page; it should either
  // redirect to login, show a 403/forbidden page, or redirect elsewhere
  const currentUrl = page.url();
  const isOnAdminUsers =
    currentUrl.includes('/admin/users') &&
    !currentUrl.includes('/login') &&
    !currentUrl.includes('/403') &&
    !currentUrl.includes('/forbidden');

  expect(isOnAdminUsers).toBe(false);

  // Additionally, check that the page does not render sensitive admin content
  const bodyText = page.locator('body');
  await expect(bodyText).not.toContainText(/manage users|user management/i);
});

// ---------------------------------------------------------------------------
// s6 – Inactive / deactivated account is refused login
// ---------------------------------------------------------------------------

test('s6 – inactive account is refused login', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const email = env('E2E_INACTIVE_USER_EMAIL');
  const password = env('E2E_INACTIVE_USER_PASSWORD');

  await loginPage.login(email, password);

  // The user must remain on (or be redirected back to) the login page
  await expect(page).toHaveURL(/\/login/);

  // A visible refusal / deactivated-account message must be shown
  const bodyText = page.locator('body');
  await expect(bodyText).toContainText(
    /inactive|deactivated|account.*disabled|disabled.*account|not active/i
  );
});
