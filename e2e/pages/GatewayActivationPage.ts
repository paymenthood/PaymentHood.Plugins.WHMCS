import { Page, expect } from '@playwright/test';
import { cfg } from '../fixtures/config.js';

/**
 * Drives the PaymentHood OAuth activation, which leaves WHMCS and goes to the
 * PaymentHood Console (real sandbox), authenticates the merchant, grants the
 * WHMCS app, and returns to configgateways.php with ?licenseId=&authorizationCode=.
 *
 * The PaymentHood Console UI is EXTERNAL and not part of this repo, so the
 * selectors below are deliberately generic + overridable. If activation fails,
 * record the real flow with:  npx playwright codegen <console-url>
 * and tighten the marked steps.
 */
export class GatewayActivationPage {
  constructor(private readonly page: Page) {}

  /** Returns true if the gateway already shows "Account is activated". */
  async isActivated(): Promise<boolean> {
    return (await this.page.getByText(/account is activated/i).count()) > 0;
  }

  /**
   * Click "Activate PaymentHood", complete the merchant login + authorization on
   * the PaymentHood Console, and wait for the redirect back into WHMCS.
   */
  async activate(): Promise<void> {
    if (await this.isActivated()) return;

    const activateBtn = this.page.getByRole('link', { name: /activate paymenthood/i });
    await expect(activateBtn, 'Activate PaymentHood button on gateway settings').toBeVisible();
    await activateBtn.click();

    // We are now on the PaymentHood Console (external origin).
    await this.page.waitForLoadState('domcontentloaded');

    await this.loginToConsoleIfNeeded();
    await this.grantAuthorization();

    // Back in WHMCS: the gateway page should now show the activated state.
    await this.page.waitForURL(/configgateways\.php/i, { timeout: 60_000 });
    await expect(this.page.getByText(/account is activated/i)).toBeVisible({ timeout: 30_000 });
  }

  // --- External PaymentHood Console steps (adjust to the real UI) ------------

  private async loginToConsoleIfNeeded(): Promise<void> {
    const emailField = this.page.locator(
      'input[type="email"], input[name="email"], input[name="username"]',
    );
    // If a login form is shown, fill it. Otherwise the merchant may already have a session.
    if (await emailField.first().isVisible().catch(() => false)) {
      await emailField.first().fill(cfg.paymenthood.merchantEmail);
      await this.page
        .locator('input[type="password"], input[name="password"]')
        .first()
        .fill(cfg.paymenthood.merchantPass);
      await this.page
        .getByRole('button', { name: /sign in|log in|login|continue/i })
        .first()
        .click();
      await this.page.waitForLoadState('networkidle');
    }
  }

  private async grantAuthorization(): Promise<void> {
    // The console shows a "Grant authorization to WHMCS app" confirmation.
    const grantBtn = this.page.getByRole('button', {
      name: /grant|authorize|allow|approve|confirm|connect/i,
    });
    if (await grantBtn.first().isVisible().catch(() => false)) {
      await grantBtn.first().click();
    }
  }
}
