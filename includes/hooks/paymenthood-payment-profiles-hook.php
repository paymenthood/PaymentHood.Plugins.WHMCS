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

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    try {
        $gateway = paymenthood_GATEWAY;

        // Only load on checkout page or invoice page (where gateway panels live)
        $filename = $_SERVER['PHP_SELF'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        PaymentHoodHandler::safeLogModuleCall('hook_execution_start', [
            'filename' => $filename,
            'requestUri' => $requestUri,
            'gateway' => $gateway
        ], []);

        if (
            strpos($filename, 'cart.php') === false
            && strpos($filename, 'checkout') === false
            && strpos($filename, 'viewinvoice.php') === false
        ) {
            PaymentHoodHandler::safeLogModuleCall('hook_skipped_wrong_page', [
                'filename' => $filename
            ], []);
            return '';
        }

        PaymentHoodHandler::safeLogModuleCall('hook_proceeding', [
            'filename' => $filename
        ], []);

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

        // Minimal debug to help diagnose missing message without logging the content
        $debug = [
            'source' => $checkoutMessageSource,
            'length' => strlen($checkoutMessage),
            'isEmpty' => ($checkoutMessage === ''),
            'hasGetGatewayVariables' => $hasGetGatewayVariables,
            'gatewayVarKeys' => array_values(array_unique(array_map('strval', array_keys($gatewayParams)))),
        ];

        // Only log when we're missing the stored value (or falling back), to reduce noise.
        $shouldLog = ($checkoutMessageSource === 'fallback.configDefault' || $checkoutMessageSource === 'empty');

        // If still empty, log what settings exist for this gateway (names + ids only)
        if ($shouldLog && $checkoutMessage === '') {
            try {
                $rows = Capsule::table('tblpaymentgateways')
                    ->select(['id', 'gateway', 'setting', 'value'])
                    ->whereRaw('TRIM(LOWER(gateway)) = ?', [strtolower($gateway)])
                    ->orderBy('id', 'asc')
                    ->get();

                $sensitive = function ($settingName) {
                    $s = strtolower((string) $settingName);
                    return (strpos($s, 'token') !== false)
                        || (strpos($s, 'secret') !== false)
                        || (strpos($s, 'password') !== false)
                        || (strpos($s, 'key') !== false)
                        || (strpos($s, 'authorization') !== false);
                };

                $settingsSummary = [];
                foreach ($rows as $r) {
                    $isSensitive = $sensitive($r->setting ?? '');
                    $settingsSummary[] = [
                        'id' => (int) ($r->id ?? 0),
                        'setting' => (string) ($r->setting ?? ''),
                        'len' => $isSensitive ? null : strlen((string) ($r->value ?? '')),
                        'redacted' => $isSensitive,
                    ];
                }

                $debug['dbRowCount'] = count($settingsSummary);
                $debug['dbSettings'] = $settingsSummary;
            } catch (\Throwable $e) {
                $debug['dbInspectError'] = $e->getMessage();
            }
        }

        if ($shouldLog) {
            PaymentHoodHandler::safeLogModuleCall('checkout_message_resolve', [], $debug);
        }

        // Get base URL for AJAX endpoint
        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
        $ajaxUrl = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php';
        $iconProxyBase = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php?proxy=1&u=';

        // Build URLs for external assets
        $gatewayAssetsBase = $systemUrl . '/modules/gateways/' . rawurlencode($gateway);
        $cssUrl = $gatewayAssetsBase . '/paymenthood-profiles.css';
        $jsUrl = $gatewayAssetsBase . '/paymenthood-profiles.js';
        $templateUrl = $gatewayAssetsBase . '/paymenthood-profiles.html';

        // Build the JSON config that the external JS reads from window.PAYMENTHOOD_CONFIG
        $jsConfig = json_encode([
            'checkoutMessage' => $checkoutMessage,
            'ajaxUrl' => $ajaxUrl,
            'iconProxyBase' => $iconProxyBase,
            'templateUrl' => $templateUrl,
        ]);

        PaymentHoodHandler::safeLogModuleCall('hook_returning_html', [
            'ajaxUrl' => $ajaxUrl,
            'iconProxyBase' => $iconProxyBase,
            'checkoutMessageLength' => strlen($checkoutMessage)
        ], []);

        return '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">'
            . '<script>window.PAYMENTHOOD_CONFIG=' . $jsConfig . ';</script>'
            . '<script src="' . htmlspecialchars($jsUrl) . '"></script>';

    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('paymenthood_profiles_hook_error', [], [
            'error' => $e->getMessage(),
        ]);
        return '';
    }
});
