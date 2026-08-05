import { test, expect } from '@playwright/test';
import { AdminPage } from '../pages/AdminPage.js';
import { GatewayActivationPage } from '../pages/GatewayActivationPage.js';
import { cfg } from '../fixtures/config.js';
import { isGatewayActivated } from '../utils/db.js';

/**
 * Step 1–3 of the user's goal: install the plugin on the local server and
 * complete the setup (activate the gateway + OAuth against the real sandbox).
 *
 * File installation itself is done by `npm run deploy` (copies plugin files into
 * WHMCS_ROOT). This spec drives the WHMCS-admin side of activation.
 */
test.describe('Setup: install & activate PaymentHood gateway', () => {
  test('admin activates the PaymentHood gateway via PaymentHood Console (sandbox)', async ({ page }) => {
    const admin = new AdminPage(page);
    const activation = new GatewayActivationPage(page);

    await admin.login();
    await admin.activateGatewayModule();
    await admin.openGatewaySettings();

    // Complete the OAuth handshake against the real PaymentHood sandbox console.
    await activation.activate();

    // Confirm activation in the UI.
    await expect(page.getByText(/account is activated/i)).toBeVisible();

    // And, if DB verification is enabled, confirm the stored flag too.
    if (cfg.db.enabled) {
      expect(await isGatewayActivated(), 'tblpaymentgateways activated flag should be 1').toBe(true);
    }
  });
});
