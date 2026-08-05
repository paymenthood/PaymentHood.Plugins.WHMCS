import { Page, expect } from '@playwright/test';
import { cfg } from '../fixtures/config.js';

/**
 * The WHMCS invoice page the customer lands on after returning from PaymentHood.
 * On success the callback redirects to:
 *   viewinvoice.php?id=<id>&paymentsuccess=true
 * and the invoice is marked Paid.
 */
export class InvoicePage {
  constructor(private readonly page: Page) {}

  /**
   * After the gateway return, wait for the WHMCS viewinvoice page and return the
   * invoice id from the URL.
   */
  async waitForReturnAndGetInvoiceId(): Promise<number> {
    await this.page.waitForURL(/viewinvoice\.php\?id=\d+/i, { timeout: 90_000 });
    const match = this.page.url().match(/viewinvoice\.php\?id=(\d+)/i);
    if (!match) throw new Error(`Could not parse invoice id from URL: ${this.page.url()}`);
    return Number(match[1]);
  }

  /** Assert the success query flag is present in the return URL. */
  async assertPaymentSuccessFlag(): Promise<void> {
    expect(this.page.url(), 'return URL should carry paymentsuccess=true').toMatch(/paymentsuccess=true/i);
  }

  /** Assert the invoice page shows a Paid status. */
  async assertInvoicePaid(invoiceId: number): Promise<void> {
    await this.page.goto(`${cfg.whmcs.baseURL}/viewinvoice.php?id=${invoiceId}`, {
      waitUntil: 'domcontentloaded',
    });
    // WHMCS shows a status label/badge with the invoice status text.
    const paid = this.page.locator('text=/\\bPaid\\b/i').first();
    await expect(paid, `invoice #${invoiceId} should be Paid`).toBeVisible({ timeout: 20_000 });
  }
}
