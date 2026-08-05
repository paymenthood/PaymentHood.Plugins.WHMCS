import { Page, expect } from '@playwright/test';
import { cfg } from '../fixtures/config.js';

/**
 * The EXTERNAL PaymentHood hosted checkout page (real sandbox). The customer is
 * redirected here after "Complete Order". They pick a provider/gateway, enter
 * card details, and pay — PaymentHood then redirects back to the WHMCS callback:
 *   .../modules/gateways/callback/paymenthood.php?invoiceid=<id>
 *
 * This UI is NOT in this repo. The steps below are generic and must be matched
 * to the actual hosted page. Easiest path: run `npm run test:purchase -- --headed`
 * once, watch the hosted page, then refine these selectors (or record with
 * `npx playwright codegen <hosted-page-url>`).
 */
export class PaymentHoodCheckoutPage {
  constructor(private readonly page: Page) {}

  /** Wait until we've actually landed on the PaymentHood hosted checkout. */
  async waitForHostedPage(): Promise<void> {
    // We left the WHMCS origin; wait for the cart redirect to settle on PaymentHood.
    await this.page.waitForURL((url) => !url.href.includes('/cart.php'), { timeout: 60_000 });
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** Choose the sandbox provider/gateway if the hosted page lists several. */
  async chooseProviderIfNeeded(): Promise<void> {
    if (cfg.paymenthood.providerName) {
      const named = this.page.getByText(new RegExp(cfg.paymenthood.providerName, 'i')).first();
      if (await named.isVisible().catch(() => false)) {
        await named.click();
        await this.page.waitForLoadState('domcontentloaded');
      }
    }
  }

  /** Fill the sandbox test card and submit the payment. */
  async payWithTestCard(): Promise<void> {
    const card = cfg.paymenthood.card;

    // Card fields are frequently inside iframes (Stripe-like). Try direct first,
    // then fall back to scanning frames.
    const filled =
      (await this.fillCardFields(this.page)) ||
      (await this.fillCardFieldsInFrames());

    if (!filled) {
      throw new Error(
        'Could not locate card input fields on the PaymentHood hosted page. ' +
          'Run headed and update PaymentHoodCheckoutPage selectors to match the real UI.',
      );
    }

    // Submit / Pay.
    const payBtn = this.page.getByRole('button', { name: /pay|confirm|submit|complete|authorize/i });
    await expect(payBtn.first(), 'Pay button on hosted page').toBeVisible({ timeout: 20_000 });
    await payBtn.first().click();
  }

  private async fillCardFields(scope: Page): Promise<boolean> {
    const card = cfg.paymenthood.card;
    const number = scope.locator(
      'input[name*="cardnumber" i], input[name*="number" i], input[autocomplete="cc-number"], input[placeholder*="card number" i]',
    );
    if (!(await number.first().isVisible().catch(() => false))) return false;

    await number.first().fill(card.number);
    await scope
      .locator('input[name*="exp" i], input[autocomplete="cc-exp"], input[placeholder*="MM" i]')
      .first()
      .fill(card.exp)
      .catch(() => {});
    await scope
      .locator('input[name*="cvc" i], input[name*="cvv" i], input[autocomplete="cc-csc"], input[placeholder*="CVC" i]')
      .first()
      .fill(card.cvc)
      .catch(() => {});
    await scope
      .locator('input[name*="name" i], input[autocomplete="cc-name"], input[placeholder*="name on card" i]')
      .first()
      .fill(card.name)
      .catch(() => {});
    return true;
  }

  private async fillCardFieldsInFrames(): Promise<boolean> {
    for (const frame of this.page.frames()) {
      const number = frame.locator('input[autocomplete="cc-number"], input[name*="number" i]');
      if (await number.first().isVisible().catch(() => false)) {
        const card = cfg.paymenthood.card;
        await number.first().fill(card.number);
        await frame.locator('input[autocomplete="cc-exp"], input[name*="exp" i]').first().fill(card.exp).catch(() => {});
        await frame.locator('input[autocomplete="cc-csc"], input[name*="cvc" i], input[name*="cvv" i]').first().fill(card.cvc).catch(() => {});
        return true;
      }
    }
    return false;
  }
}
