import { Page, expect } from '@playwright/test';
import { cfg, GATEWAY } from '../fixtures/config.js';

/**
 * Customer-facing storefront: client login/registration, adding a product to the
 * cart, and completing checkout with PaymentHood selected as the payment method.
 *
 * Targets the default WHMCS "twenty-one" / "six" client themes. Selectors fall
 * back across common variants; tighten with `npx playwright codegen <baseURL>`
 * if your theme differs.
 */
export class StorefrontPage {
  constructor(private readonly page: Page) {}

  // --- Authentication --------------------------------------------------------

  async loginClient(): Promise<void> {
    await this.page.goto(`${cfg.whmcs.baseURL}/clientarea.php`, { waitUntil: 'domcontentloaded' });

    // Already logged in? clientarea shows the dashboard, not a login form.
    if (!(await this.page.locator('#inputEmail, input[name="username"]').first().count())) {
      return;
    }

    await this.page.fill('#inputEmail, input[name="username"]', cfg.client.email);
    await this.page.fill('#inputPassword, input[name="password"]', cfg.client.password);
    await this.page.click('#login, button[type="submit"]');
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** Register a brand-new client (when CLIENT_REGISTER=true). Idempotent-ish. */
  async registerClient(): Promise<void> {
    await this.page.goto(`${cfg.whmcs.baseURL}/register.php`, { waitUntil: 'domcontentloaded' });
    await this.page.fill('#inputFirstName, input[name="firstname"]', cfg.client.firstName);
    await this.page.fill('#inputLastName, input[name="lastname"]', cfg.client.lastName);
    await this.page.fill('#inputEmail, input[name="email"]', cfg.client.email);

    // Address fields are often required by WHMCS registration.
    await this.fillIfPresent('input[name="address1"]', '123 Test St');
    await this.fillIfPresent('input[name="city"]', 'Testville');
    await this.fillIfPresent('input[name="postcode"]', '12345');
    await this.fillIfPresent('input[name="phonenumber"]', '5551234567');

    await this.page.fill('#inputNewPassword1, input[name="password"]', cfg.client.password);
    await this.fillIfPresent('#inputNewPassword2, input[name="password2"]', cfg.client.password);

    await this.page.getByRole('button', { name: /register|create|sign up/i }).first().click();
    await this.page.waitForLoadState('domcontentloaded');
  }

  // --- Shopping --------------------------------------------------------------

  /** Add the configured test product to the cart and proceed to checkout. */
  async addProductToCartAndCheckout(): Promise<void> {
    // Direct add-to-cart URL — the most stable entry point across themes.
    await this.page.goto(
      `${cfg.whmcs.baseURL}/cart.php?a=add&pid=${encodeURIComponent(cfg.product.pid)}`,
      { waitUntil: 'domcontentloaded' },
    );

    // Product configuration page: pick billing cycle if a selector is present.
    const cycle = this.page.locator('select[name="billingcycle"]');
    if (await cycle.count()) {
      // Try by option value, then by case-insensitive label, then leave the default.
      await cycle.selectOption({ value: cfg.product.billingCycle }).catch(async () => {
        const labels = await cycle.locator('option').allTextContents();
        const match = labels.find((l) => l.toLowerCase().includes(cfg.product.billingCycle.toLowerCase()));
        if (match) {
          await cycle.selectOption({ label: match }).catch(() => {});
        }
      });
    }

    // Some products require a domain choice — choose "use my own"/skip if shown.
    const continueBtn = this.page.getByRole('button', { name: /continue|add to cart|update cart/i });
    if (await continueBtn.first().isVisible().catch(() => false)) {
      await continueBtn.first().click();
      await this.page.waitForLoadState('domcontentloaded');
    }

    // Go to the cart view, then to checkout.
    await this.page.goto(`${cfg.whmcs.baseURL}/cart.php?a=view`, { waitUntil: 'domcontentloaded' });
    await this.page
      .getByRole('link', { name: /checkout|continue to checkout/i })
      .first()
      .click()
      .catch(async () => {
        await this.page.goto(`${cfg.whmcs.baseURL}/cart.php?a=checkout`, { waitUntil: 'domcontentloaded' });
      });
    await this.page.waitForLoadState('domcontentloaded');
  }

  /**
   * On the checkout page: select PaymentHood and submit "Complete Order".
   * The plugin then JS-redirects to the PaymentHood hosted checkout. Returns the
   * invoice id parsed from the resulting URL/flow.
   */
  async selectPaymentHoodAndCompleteOrder(): Promise<void> {
    // Select the PaymentHood payment method radio.
    const phRadio = this.page.locator(`input[name="paymentmethod"][value="${GATEWAY}"]`);
    await expect(phRadio, 'PaymentHood payment option on checkout').toHaveCount(1);
    await phRadio.check({ force: true });

    // Accept ToS if the checkbox is present and required.
    const tos = this.page.locator('#accepttos, input[name="accepttos"]');
    if (await tos.count()) {
      await tos.first().check({ force: true }).catch(() => {});
    }

    // Submit the order. WHMCS uses #btnCompleteOrder on the standard cart.
    const complete = this.page.locator(
      '#btnCompleteOrder, button#checkout, button[name="submit"], button[type="submit"]',
    );
    await complete.first().click();
  }

  // --- helpers ---------------------------------------------------------------

  private async fillIfPresent(selector: string, value: string): Promise<void> {
    const el = this.page.locator(selector);
    if (await el.count()) {
      await el.first().fill(value).catch(() => {});
    }
  }
}
