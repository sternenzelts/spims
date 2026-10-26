import { type Page, type Locator, expect } from '@playwright/test';

export class HomePage {
  readonly page: Page;
  readonly heroHeading: Locator;
  readonly logInLink: Locator;
  readonly explorePlatformButton: Locator;
  readonly carouselTablist: Locator;
  readonly tabAutomation: Locator;
  readonly tabManual: Locator;
  readonly tabApi: Locator;
  readonly tabPerformance: Locator;
  readonly nextButton: Locator;
  readonly prevButton: Locator;
  readonly carouselHeadingAutomation: Locator;
  readonly kairoProposesHeading: Locator;
  readonly replayButton: Locator;
  readonly evaluatingHeading: Locator;
  readonly koraTestLink: Locator;
  readonly robotMascotImage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heroHeading = page.getByRole('heading', { name: 'Your tests. In a new light.' });
    this.logInLink = page.getByRole('link', { name: 'Log in to Kora' });
    this.explorePlatformButton = page.getByRole('button', { name: 'Explore the platform' });
    this.carouselTablist = page.getByRole('tablist', { name: 'Choose a testing area' });
    this.tabAutomation = page.getByRole('tab', { name: 'Automation' });
    this.tabManual = page.getByRole('tab', { name: 'Manual' });
    this.tabApi = page.getByRole('tab', { name: 'API' });
    this.tabPerformance = page.getByRole('tab', { name: 'Performance' });
    this.nextButton = page.getByRole('button', { name: 'Next: Manual' });
    this.prevButton = page.getByRole('button', { name: 'Previous: Performance' });
    this.carouselHeadingAutomation = page.getByRole('heading', { name: 'Automation' });
    this.kairoProposesHeading = page.getByRole('heading', { name: 'Kairo proposes. You approve.' });
    this.replayButton = page.getByRole('button', { name: 'Replay' });
    this.evaluatingHeading = page.getByRole('heading', { name: 'Evaluating Kora for your organisation?' });
    this.koraTestLink = page.getByRole('link', { name: 'Kora Test' });
    this.robotMascotImage = page.getByRole('img', { name: 'Kairo, the robot mascot of the Quality Agent, which is in development.' });
  }

  async goto() {
    await this.page.goto('/');
  }
}
