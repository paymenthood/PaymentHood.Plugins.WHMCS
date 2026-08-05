# PaymentHood WHMCS — End-to-End Tests

Playwright (TypeScript) E2E suite that drives the full PaymentHood plugin journey
against a **local WHMCS install (XAMPP/WAMP/local PHP)** using the **real
PaymentHood sandbox**:

1. **Install** the plugin files into your WHMCS root.
2. **Setup** — activate the gateway and complete OAuth against the PaymentHood Console.
3. **Buy** — a customer adds a test product, checks out with PaymentHood.
4. **Pay** — on the real PaymentHood hosted checkout (sandbox card).
5. **Validate** — back in the shop, assert the invoice is **Paid** and the order is **Active**.

---

## Prerequisites

- A working **local WHMCS** install (e.g. `http://localhost/whmcs`) with:
  - At least one **test product** (note its `pid`).
  - A **client account** to shop with (or let the suite register one).
  - WHMCS admin credentials.
- **Node.js 18+**.
- A **PaymentHood merchant sandbox** account (for OAuth activation + test payments).

> The PaymentHood callback needs to be reachable. On localhost the **browser
> return (GET)** works fine and marks the invoice Paid. The server-to-server
> **webhook (POST)** requires a publicly reachable URL — if you want webhooks too,
> expose WHMCS with a tunnel (e.g. ngrok) and set your WHMCS *System URL* to it.

---

## 1. Configure

```bash
cd e2e
npm install
npx playwright install chromium
cp .env.example .env        # then edit .env
```

Fill in `.env` — at minimum: `WHMCS_BASE_URL`, `WHMCS_ROOT`, admin creds, a
client login, `PRODUCT_PID`, the PaymentHood merchant creds, and a sandbox test
card. Set `DB_*` to also assert invoice/order status directly from MySQL
(recommended — the storefront doesn't show raw order status to customers).

## 2. Install the plugin files

```bash
npm run deploy          # copies includes/ and modules/ into WHMCS_ROOT
```

This mirrors the manual "upload all files preserving structure" step from the
plugin README. It merges into your WHMCS install without touching other modules.

## 3. Run setup (activate gateway)

```bash
npm run test:setup
```

Logs into WHMCS admin, activates the PaymentHood module, then drives the OAuth
handshake on the PaymentHood Console (sandbox) until the gateway shows
**"Account is activated"**.

## 4. Run the purchase flow

```bash
npm run test:purchase
```

Or run everything in order:

```bash
npm test
```

Useful while iterating on selectors:

```bash
npm run test:headed     # watch the browser
npm run test:ui         # Playwright UI mode
npm run report          # open the last HTML report
```

---

## Project layout

```
e2e/
├── playwright.config.ts        # base URL, timeouts, serial execution
├── .env.example                # copy to .env and fill in
├── scripts/deploy-plugin.mjs   # copies plugin into WHMCS_ROOT
├── fixtures/config.ts          # typed env config (cfg.*)
├── utils/db.ts                 # optional MySQL assertions (invoice/order/gateway)
├── pages/
│   ├── AdminPage.ts                 # admin login, module activation, order lookup
│   ├── GatewayActivationPage.ts     # OAuth against PaymentHood Console (external)
│   ├── StorefrontPage.ts            # client auth, cart, checkout
│   ├── PaymentHoodCheckoutPage.ts   # hosted checkout / sandbox card (external)
│   └── InvoicePage.ts               # return handling + Paid assertion
└── tests/
    ├── 01-setup.spec.ts        # install + activate
    └── 02-purchase.spec.ts     # buy + validate
```

---

## Adjusting selectors (important)

Two parts of the flow live on **external PaymentHood pages** that are not in this
repo, so their selectors are best-effort and intentionally generic:

- `pages/GatewayActivationPage.ts` — the PaymentHood **Console** login + grant screen.
- `pages/PaymentHoodCheckoutPage.ts` — the **hosted checkout** card form / pay button.

WHMCS-side selectors (admin, storefront, checkout) target the default
`twenty-one`/`six` themes and may need tweaks for custom themes.

The fastest way to fix a mismatch:

```bash
npx playwright codegen http://localhost/whmcs            # WHMCS pages
npx playwright codegen <paymenthood-console-or-checkout-url>   # external pages
```

Record the real interaction, then copy the exact selectors into the marked spots.
Running `npm run test:purchase -- --headed` once and watching where it stalls
tells you which page object to refine.

---

## How validation maps to the plugin code

- On `paymentState === 'Captured'` the callback
  (`modules/gateways/callback/paymenthood.php`) records the payment → invoice
  becomes **Paid**, calls `AcceptOrder` → order becomes **Active**, then redirects
  to `viewinvoice.php?id=<id>&paymentsuccess=true`.
- `02-purchase.spec.ts` asserts that return URL flag, the **Paid** badge on the
  invoice, and (with `DB_*` set) `tblinvoices.status = Paid` +
  `tblorders.status = Active`.
```
