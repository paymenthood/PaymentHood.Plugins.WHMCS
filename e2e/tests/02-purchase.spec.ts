import { test, expect } from '@playwright/test';
import { StorefrontPage } from '../pages/StorefrontPage.js';
import { PaymentHoodCheckoutPage } from '../pages/PaymentHoodCheckoutPage.js';
import { InvoicePage } from '../pages/InvoicePage.js';
import { cfg } from '../fixtures/config.js';
import { getInvoiceStatus, getOrderStatusByInvoice } from '../utils/db.js';

/**
 * Step 4–5 of the user's goal: in the shop area, a customer buys a test product,
 * pays on the PaymentHood sandbox, is redirected back to the shop, and we
 * validate the order/invoice status.
 *
 * Full journey:
 *   storefront login → add product → checkout (select PaymentHood) → Complete
 *   Order → redirected to PaymentHood hosted checkout → pay with sandbox card →
 *   redirected back to viewinvoice.php?id=<id>&paymentsuccess=true → invoice Paid,
 *   order Active.
 */
test.describe('Purchase: buy a test product and validate order status', () => {
  test('customer completes a sandbox purchase and the invoice/order are marked paid/active', async ({ page }) => {
    const store = new StorefrontPage(page);
    const hosted = new PaymentHoodCheckoutPage(page);
    const invoice = new InvoicePage(page);

    // 1. Authenticate the customer.
    if (cfg.client.register) {
      await store.registerClient();
    } else {
      await store.loginClient();
    }

    // 2. Shop: add the test product and go to checkout.
    await store.addProductToCartAndCheckout();

    // 3. Select PaymentHood and complete the order — triggers redirect to sandbox.
    await store.selectPaymentHoodAndCompleteOrder();

    // 4. Pay on the external PaymentHood hosted checkout (real sandbox).
    await hosted.waitForHostedPage();
    await hosted.chooseProviderIfNeeded();
    await hosted.payWithTestCard();

    // 5. Back on the WHMCS shop: validate the return + invoice status.
    const invoiceId = await invoice.waitForReturnAndGetInvoiceId();
    await invoice.assertPaymentSuccessFlag();
    await invoice.assertInvoicePaid(invoiceId);

    // 6. Stronger validation via DB (optional): invoice Paid + order Active.
    if (cfg.db.enabled) {
      expect(await getInvoiceStatus(invoiceId), `invoice #${invoiceId} status`).toBe('Paid');

      const orderStatus = await getOrderStatusByInvoice(invoiceId);
      // The callback calls AcceptOrder on success → order becomes Active.
      expect(orderStatus, `order for invoice #${invoiceId} status`).toBe('Active');
    }
  });
});
