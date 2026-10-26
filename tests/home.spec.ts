import { test, expect } from '@playwright/test';
import { HomePage } from './pages/HomePage';
import { LoginPage } from './pages/LoginPage';

// ---------------------------------------------------------------------------
// s1 – Home page renders key content
// ---------------------------------------------------------------------------
test('[s1] Home page renders key content', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  // Page must respond successfully (Playwright throws on network error by default)
  await expect(home.heroHeading).toBeVisible();
  await expect(home.logInLink).toBeVisible();
  await expect(home.explorePlatformButton).toBeVisible();
});

// ---------------------------------------------------------------------------
// s2 – Home page carousel – first slide and navigation controls are visible
// Assumption: full four-slide content assertions are out of scope because the
// copy of slides 2-4 was not provided. We verify the tab list, all four tabs,
// and the Previous/Next buttons together with the first-slide heading.
// ---------------------------------------------------------------------------
test('[s2] Home page carousel – first slide and navigation controls are visible', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  await expect(home.carouselTablist).toBeVisible();
  await expect(home.tabAutomation).toBeVisible();
  await expect(home.tabManual).toBeVisible();
  await expect(home.tabApi).toBeVisible();
  await expect(home.tabPerformance).toBeVisible();
  await expect(home.nextButton).toBeVisible();
  await expect(home.prevButton).toBeVisible();
  await expect(home.carouselHeadingAutomation).toBeVisible();
});

// ---------------------------------------------------------------------------
// s3 – Home page 'Quality Agent preview' section is visible
// Assumption: the code snippet visible in the preview contains deliberate typos
// (e.g. '/loign', 'sumbit') and is treated as static display content; we do
// not assert its exact text, only the surrounding section headings and controls.
// ---------------------------------------------------------------------------
test('[s3] Home page \'Quality Agent preview\' section is visible', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  await expect(home.kairoProposesHeading).toBeVisible();
  await expect(home.replayButton).toBeVisible();
  await expect(home.evaluatingHeading).toBeVisible();
  await expect(home.koraTestLink).toBeVisible();
});

// ---------------------------------------------------------------------------
// s10 – 'Log in to Kora' link navigates to the login page
// ---------------------------------------------------------------------------
test('[s10] \'Log in to Kora\' link navigates to the login page', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  await home.logInLink.click();
  await page.waitForURL('**/login');

  await expect(page).toHaveURL(/\/login/);

  const login = new LoginPage(page);
  await expect(login.usernameInput).toBeVisible();
  await expect(login.passwordInput).toBeVisible();
});

// ---------------------------------------------------------------------------
// s12 – Home page robot mascot image has a non-empty alt attribute
// ---------------------------------------------------------------------------
test('[s12] Home page robot mascot image has a non-empty alt attribute', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  // getByRole('img', { name: '…' }) already matches on the alt text, so finding
  // the element is itself the accessibility assertion.
  await expect(home.robotMascotImage).toBeVisible();
  // Explicitly confirm the alt attribute is non-empty
  const alt = await home.robotMascotImage.getAttribute('alt');
  expect(alt).toBeTruthy();
  expect(alt!.trim().length).toBeGreaterThan(0);
});
