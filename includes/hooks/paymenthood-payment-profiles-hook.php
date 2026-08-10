<?php
/**
 * PaymentHood Checkout Payment Profiles Hook
 * Displays available payment profiles when PaymentHood gateway is selected
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

if (!defined('paymenthood_GATEWAY')) {
    define('paymenthood_GATEWAY', 'paymenthood');
}

if (!function_exists('paymenthoodHasExistingInvoicePayment')) {
    function paymenthoodHasExistingInvoicePayment(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        if (!empty($_SESSION['paymenthood_redirect_' . $invoiceId])) {
            return true;
        }

        try {
            $credentials = PaymentHoodHandler::getGatewayCredentials();
            $phAppId = $credentials['appId'] ?? '';
            $phToken = $credentials['token'] ?? '';

            if (!$phAppId || !$phToken) {
                return false;
            }

            $checkUrl = PaymentHoodHandler::paymenthood_getPaymentBaseUrl()
                . "/apps/{$phAppId}/payments/referenceId:{$invoiceId}";

            $ch = curl_init($checkUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer $phToken",
                    'Accept: application/json',
                ],
            ]);

            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code !== 404 && $code !== 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('paymenthoodFilterInvoiceGateways')) {
    function paymenthoodFilterInvoiceGateways(array $gatewaysVar): array
    {
        $isNested = isset($gatewaysVar['gateways']) && is_array($gatewaysVar['gateways']);
        $gatewayList = $isNested ? $gatewaysVar['gateways'] : $gatewaysVar;
        $filteredGateways = [];

        foreach ($gatewayList as $idx => $gateway) {
            if (!is_array($gateway)) {
                continue;
            }

            $sysname = strtolower((string) ($gateway['sysname']
                ?? $gateway['module']
                ?? $gateway['gateway']
                ?? $gateway['paymentmethod']
                ?? $idx));

            if ($sysname !== paymenthood_GATEWAY) {
                continue;
            }

            $filteredGateways[$idx] = $gateway;
        }

        if (empty($filteredGateways)) {
            return [];
        }

        if ($isNested) {
            $gatewaysVar['gateways'] = $filteredGateways;

            return ['gateways' => $gatewaysVar];
        }

        return ['gateways' => $filteredGateways];
    }
}

add_hook('ClientAreaPageViewInvoice', 1, function ($vars) {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['id'] ?? $_GET['id'] ?? 0);
        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')
            ->select('status', 'paymentmethod')
            ->where('id', $invoiceId)
            ->first();

        if ($invoice === null || $invoice->status !== 'Unpaid') {
            return [];
        }

        if (!paymenthoodHasExistingInvoicePayment($invoiceId)) {
            return [];
        }

        $filteredGateways = paymenthoodFilterInvoiceGateways((array) ($vars['gateways'] ?? []));

        return !empty($filteredGateways) ? $filteredGateways : [];
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('paymenthood_invoice_gateway_filter_error', [], [
            'error' => $e->getMessage(),
        ]);

        return [];
    }
});

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    try {
        $gateway = paymenthood_GATEWAY;

        // Only load on checkout page or invoice page (where gateway panels live)
        $filename = $_SERVER['PHP_SELF'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (
            strpos($filename, 'cart.php') === false
            && strpos($filename, 'checkout') === false
            && strpos($filename, 'viewinvoice.php') === false
        ) {
            return '';
        }

        // Get gateway settings including checkout message
        // WHMCS may normalize gateway setting keys to lowercase in some contexts/versions.
        if (!function_exists('getGatewayVariables') && defined('ROOTDIR')) {
            $gatewayFunctionsPath = ROOTDIR . '/includes/gatewayfunctions.php';
            if (is_file($gatewayFunctionsPath)) {
                require_once $gatewayFunctionsPath;
            }
        }

        $hasGetGatewayVariables = function_exists('getGatewayVariables');
        $gatewayParams = $hasGetGatewayVariables ? (array) getGatewayVariables($gateway) : [];
        $checkoutMessageSource = 'empty';

        // Determine if the admin explicitly cleared the checkout message field.
        // We check the DB directly because getGatewayVariables() merges the config
        // Default into its output, making it impossible to distinguish "cleared"
        // from "never saved". We use this flag only as a gate — the actual value
        // is still read from getGatewayVariables() which handles fresh-install
        // defaults and properly saved values reliably.
        $adminExplicitlyCleared = false;
        try {
            $dbRow = Capsule::table('tblpaymentgateways')
                ->whereRaw('TRIM(LOWER(gateway)) = ?', [strtolower($gateway)])
                ->whereRaw('LOWER(setting) = ?', ['checkoutmessage'])
                ->orderByDesc('id')
                ->first();

            // Row exists AND value is empty → admin cleared the field intentionally
            if ($dbRow !== null && trim((string) ($dbRow->value ?? '')) === '') {
                $adminExplicitlyCleared = true;
            }
        } catch (\Throwable $e) {
            // Cannot determine — assume not cleared, fall through to getGatewayVariables()
        }

        if ($adminExplicitlyCleared) {
            $checkoutMessage = '';
            $checkoutMessageSource = 'db.explicitlyEmpty';
        } elseif (isset($gatewayParams['checkoutMessage']) && trim((string) $gatewayParams['checkoutMessage']) !== '') {
            $checkoutMessage = trim((string) $gatewayParams['checkoutMessage']);
            $checkoutMessageSource = 'gatewayVars.checkoutMessage';
        } elseif (isset($gatewayParams['checkoutmessage']) && trim((string) $gatewayParams['checkoutmessage']) !== '') {
            $checkoutMessage = trim((string) $gatewayParams['checkoutmessage']);
            $checkoutMessageSource = 'gatewayVars.checkoutmessage';
        } else {
            $checkoutMessage = '';
        }

        $checkoutMessage = trim((string) $checkoutMessage);

        // Guard: reject values that look like tokens/hashes (long hex-only strings)
        // rather than human-readable messages. This prevents secrets from leaking
        // onto the checkout page if a corrupt DB row is returned.
        if ($checkoutMessage !== '' && preg_match('/^[0-9a-f]{32,}$/i', $checkoutMessage)) {
            PaymentHoodHandler::safeLogModuleCall('checkout_message_rejected_token', [], [
                'length' => strlen($checkoutMessage),
                'source' => $checkoutMessageSource,
            ]);
            $checkoutMessage = '';
        }

        // Get base URL for AJAX endpoint
        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
        $ajaxUrl = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php';
        $iconProxyBase = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php?proxy=1&u=';

        // Build URLs for external assets.
        //
        // Each carries ?v=<file mtime>. Without it the browser keeps serving
        // the previously cached .js/.css/.html after a plugin upload, so a new
        // build appears to change nothing at all until the cache expires. The
        // stamp changes whenever the file is re-uploaded, which is exactly when
        // the cache must be invalidated.
        $gatewayAssetsBase = $systemUrl . '/modules/gateways/' . rawurlencode($gateway);
        $assetDir = __DIR__ . '/../../modules/gateways/' . $gateway;

        $assetUrl = function ($filename) use ($gatewayAssetsBase, $assetDir) {
            $url = $gatewayAssetsBase . '/' . $filename;
            $path = $assetDir . '/' . $filename;
            $stamp = is_file($path) ? @filemtime($path) : false;

            return $stamp === false ? $url : $url . '?v=' . $stamp;
        };

        $cssUrl = $assetUrl('paymenthood-profiles.css');
        $jsUrl = $assetUrl('paymenthood-profiles.js');
        $templateUrl = $assetUrl('paymenthood-profiles.html');

        // Build the JSON config that the external JS reads from window.PAYMENTHOOD_CONFIG
        $jsConfigData = [
            'checkoutMessage'          => $checkoutMessage,
            'ajaxUrl'                  => $ajaxUrl,
            'iconProxyBase'            => $iconProxyBase,
            'templateUrl'              => $templateUrl,
            'blockCreditApplication'   => false,
            'blockCreditMessage'       => '',
            'blockPaymentMethodSwitch' => false,
            'blockPaymentMethodMsg'    => '',
        ];

        $output = '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">'
            . '<script>window.PAYMENTHOOD_CONFIG=' . json_encode($jsConfigData) . ';</script>'
            . '<script src="' . htmlspecialchars($jsUrl) . '"></script>';

        $blockCreditMsg  = 'A PaymentHood payment is in progress for this invoice. Credit cannot be applied while a payment is pending.';
        $blockMethodMsg  = 'A PaymentHood payment is already in progress for this invoice. You cannot switch payment methods until it completes or expires.';

        // ── viewinvoice.php — hide other payment methods + block Apply Credit ──
        if (strpos($filename, 'viewinvoice.php') !== false) {
            try {
                $invoiceId = (int) ($_GET['id'] ?? 0);
                if ($invoiceId > 0) {
                    $invoice = Capsule::table('tblinvoices')
                        ->select('status', 'paymentmethod')
                        ->where('id', $invoiceId)
                        ->first();

                    // When the invoice belongs to PaymentHood, hide the gateway
                    // dropdown entirely with server-rendered CSS so the customer
                    // never sees Stripe / PayPal / etc. No JS or API call needed.
                    if ($invoice && strtolower((string) $invoice->paymentmethod) === $gateway) {
                        $output .= PaymentHoodHandler::renderTemplate('invoice-hide-elements', [
                            'blockCreditMsg' => $blockCreditMsg,
                            'blockMethodMsg' => $blockMethodMsg,
                            'isUnpaid'       => $invoice->status === 'Unpaid',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Never break the page
            }
        }

        // ── cart.php (checkout) — block credit + payment method switch ────────
        if (strpos($filename, 'cart.php') !== false || strpos($requestUri, 'checkout') !== false) {
            try {
                // The checkout page has already committed to PaymentHood as the
                // session's cart that uses PaymentHood and has a payment in-flight.
                $sessionInvoiceId = (int) ($_SESSION['invoiceid'] ?? 0);

                // Fallback: look up the latest Unpaid PaymentHood invoice for this client
                if ($sessionInvoiceId <= 0 && !empty($_SESSION['uid'])) {
                    $sessionInvoiceId = (int) Capsule::table('tblinvoices')
                        ->where('userid', (int) $_SESSION['uid'])
                        ->where('paymentmethod', 'paymenthood')
                        ->where('status', 'Unpaid')
                        ->orderByDesc('id')
                        ->value('id');
                }

                if ($sessionInvoiceId > 0 && paymenthoodHasExistingInvoicePayment($sessionInvoiceId)) {
                    $output .= '<script>if(window.PAYMENTHOOD_CONFIG){'
                        . 'window.PAYMENTHOOD_CONFIG.blockCreditApplication=true;'
                        . 'window.PAYMENTHOOD_CONFIG.blockCreditMessage=' . json_encode($blockCreditMsg) . ';'
                        . 'window.PAYMENTHOOD_CONFIG.blockPaymentMethodSwitch=true;'
                        . 'window.PAYMENTHOOD_CONFIG.blockPaymentMethodMsg=' . json_encode($blockMethodMsg) . ';'
                        . '}</script>';
                }
            } catch (\Throwable $e) {
                // Never break the page
            }
        }

        return $output;

    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('paymenthood_profiles_hook_error', [], [
            'error' => $e->getMessage(),
        ]);
        return '';
    }
});
