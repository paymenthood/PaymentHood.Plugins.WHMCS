<?php
use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

require_once __DIR__ . '/../addons/paymenthood/paymenthoodhandler.php';

// Load WHMCS functions if not already loaded
if (!function_exists('addInvoiceRefund')) {
    require_once ROOTDIR . '/includes/invoicefunctions.php';
}
if (!function_exists('localAPI')) {
    require_once ROOTDIR . '/includes/functions.php';
}

define('paymenthood_GATEWAY', 'paymenthood');

// Handle activation return before any output
paymenthood_handleActivationReturn();

/**
 * Gateway module metadata (used by WHMCS module discovery/UI, including Apps & Integrations).
 */
function paymenthood_MetaData()
{
    return [
        'DisplayName' => 'PaymentHood (Secure Invoice Payments)',
        // WHMCS gateway module API version (1.1 is current for modern gateway modules).
        'APIVersion' => '1.1',
    ];
}

function paymenthood_config()
{
    // Get current activation status
    $activated = Capsule::table('tblpaymentgateways')
        ->where('gateway', paymenthood_GATEWAY)
        ->where('setting', 'activated')
        ->value('value');

    // Keep the gateway name stable in admin + gateway lists.
    // Sandbox indication is shown only on the checkout page via a client-area hook.
    $friendlyName = 'PaymentHood';
    // Build base configuration
    $config = [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => $friendlyName,
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Accept payments through PaymentHood with support for multiple payment gateways, subscription management, and secure payment processing. Seamlessly integrate with your WHMCS billing system for automated invoice payments.'
        ],
        'activation' => [
            'FriendlyName' => 'Activation',
            'Type' => 'system',
            'Description' => paymenthood_getActivationLink($activated),
        ],
        'IsSandboxActivated' => [
            'FriendlyName' => 'Use Sandbox Mode',
            'Type' => 'yesno',
            'Description' => 'Enable to use sandbox credentials for testing. Disable to use live credentials for production.'
                . ($activated == '1' ? '' : ' (Activate PaymentHood to apply this setting.)'),
        ],
    ];

    // Add extra links only if activated
    if ($activated == '1') {
        $config['manageSandboxGateways'] = [
            'FriendlyName' => 'Manage Sandbox Gateways',
            'Type' => 'system',
            'Description' => paymenthood_getManageSandboxGatewaysLink(),
        ];

        $config['manageLiveGateways'] = [
            'FriendlyName' => 'Manage Live Gateways',
            'Type' => 'system',
            'Description' => paymenthood_getManageLiveGatewaysLink(),
        ];
    }

    return $config;
}

function paymenthood_getActivationLink($activated)
{
    // Build return URL
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
        . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";

    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $licenseId = $credentials['licenseId'];

    $paymenthoodUrl = PaymentHoodHandler::paymenthood_grantAuthorizationUrl()
        . '?returnUrl=' . urlencode($currentUrl)
        . '&licenseId=' . urlencode($licenseId)
        . '&grantAuthorization=' . urlencode('true');

    PaymentHoodHandler::safeLogModuleCall('gateway_activation_link_generated', [
        'licenseId' => $licenseId,
        'returnUrl' => $currentUrl,
        'activated' => $activated
    ], [
        'url' => $paymenthoodUrl
    ]);

    if ($activated == '1') {
        return '<span style="color:#28a745;font-weight:bold;">✓ Account is activated</span>';
    }

    return '<a href="' . htmlspecialchars($paymenthoodUrl) . '" 
                style="padding:8px 16px;background:#007bff;color:white;border-radius:4px;text-decoration:none;display:inline-block;">
                Activate PaymentHood
            </a>';
}

function paymenthood_getManageSandboxGatewaysLink()
{
    $sandboxAppId = PaymentHoodHandler::getGatewaySandboxAppId();
    $manageUrl = PaymentHoodHandler::paymenthood_ConsoleUrl() . '/' . urlencode($sandboxAppId) . '/gateways';
    return '<a href="' . htmlspecialchars($manageUrl) . '" 
                target="_blank"
                style="padding:8px 16px;background:#28a745;color:white;border-radius:4px;text-decoration:none;display:inline-block;">
                Manage Sandbox Gateways in PaymentHood Console
            </a>';
}

function paymenthood_getManageLiveGatewaysLink()
{
    $liveAppId = PaymentHoodHandler::getGatewayLiveAppId();
    $manageUrl = PaymentHoodHandler::paymenthood_ConsoleUrl() . '/' . urlencode($liveAppId) . '/gateways';
    return '<a href="' . htmlspecialchars($manageUrl) . '" 
                target="_blank"
                style="padding:8px 16px;background:#28a745;color:white;border-radius:4px;text-decoration:none;display:inline-block;">
                Manage Live Gateways in PaymentHood Console
            </a>';
}

function paymenthood_handleActivationReturn()
{
    if (isset($_GET['licenseId']) && isset($_GET['authorizationCode'])) {
        $licenseId = $_GET['licenseId'];
        $authorizationCode = $_GET['authorizationCode'];

        PaymentHoodHandler::safeLogModuleCall('gateway_activation_return', [
            'licenseId' => $licenseId
        ], []);

        // Call PaymentHood API to get app credentials
        $baseUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl();
        $url = $baseUrl . "/licenses/" . urlencode($licenseId);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $authorizationCode,
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        PaymentHoodHandler::safeLogModuleCall('gateway_activation_generate_token', [
            'url' => $url
        ], [
            'httpCode' => $httpCode,
            'responseLength' => strlen($response)
        ]);

        if ($httpCode == 200 && !empty($response)) {
            // Parse JSON array response
            $apps = json_decode($response, true);

            if (!is_array($apps)) {
                PaymentHoodHandler::safeLogModuleCall('gateway_activation_invalid_response', [
                    'url' => $url
                ], [
                    'error' => 'Response is not a valid JSON array',
                    'response' => $response
                ]);
                return;
            }

            PaymentHoodHandler::safeLogModuleCall('gateway_activation_apps_received', [
                'count' => count($apps)
            ], [
                'apps' => $apps
            ]);

            // Process each app (live and sandbox)
            foreach ($apps as $app) {
                $isSandbox = $app['isSandbox'] ?? false;
                $appId = $app['appId'] ?? '';
                $appAuthCode = $app['authorizationCode'] ?? '';

                if (empty($appId) || empty($appAuthCode)) {
                    PaymentHoodHandler::safeLogModuleCall('gateway_activation_missing_fields', [
                        'isSandbox' => $isSandbox
                    ], [
                        'app' => $app
                    ]);
                    continue;
                }

                // Save credentials with descriptive field names
                if ($isSandbox) {
                    paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'SandboxAppId', $appId);
                    paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'SandboxAppToken', $appAuthCode);
                } else {
                    paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'LiveAppId', $appId);
                    paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'LiveAppToken', $appAuthCode);
                }

                PaymentHoodHandler::safeLogModuleCall('gateway_credentials_saved', [
                    'appId' => $appId,
                    'isSandbox' => $isSandbox
                ], []);

                // Sync webhook for this app
                paymenthood_syncWebhookToken($appId, $appAuthCode);
            }

            // Mark gateway as activated
            paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'activated', '1');

            // Set sandbox mode as default (on = checkbox checked)
            paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'IsSandboxActivated', 'on');

            // Store license Id
            paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'licenseId', $licenseId);

            // Redirect to clean URL (remove query parameters)
            $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
            header("Location: " . $cleanUrl);
            exit;
        } else {
            PaymentHoodHandler::safeLogModuleCall('gateway_activation_failed', [
                'url' => $url
            ], [
                'httpCode' => $httpCode,
                'response' => $response
            ]);
        }
    }
}

function paymenthood_saveCredentials($appId, $accessToken)
{
    try {
        // Save App ID
        paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'appId', $appId);

        // Save Token
        paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'token', $accessToken);

        // Mark as activated
        paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'activated', '1');

        PaymentHoodHandler::safeLogModuleCall('gateway_credentials_saved', [
            'appId' => $appId
        ], []);
    } catch (\Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway_credentials_save_error', [
            'appId' => $appId
        ], [
            'error' => $e->getMessage()
        ]);
    }
}

function paymenthood_syncWebhookToken($appId, $token)
{
    PaymentHoodHandler::safeLogModuleCall('gateway_webhook_registration_start', [
        'appId' => $appId
    ], []);

    // Get or create webhook token
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $webhookToken = $credentials['webhookToken'] ?? null;

    if (!$webhookToken) {
        // Create token if missing
        $webhookToken = bin2hex(random_bytes(32));
        paymenthood_saveGatewaySetting(paymenthood_GATEWAY, 'webhookToken', $webhookToken);
    }

    $payload = [
        "webhookAuthorizationHeaderScheme" => ["value" => "Bearer"],
        "webhookAuthorizationHeaderParameter" => ["value" => $webhookToken],
    ];

    $headers = [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json",
        "Accept: text/plain"
    ];

    $baseUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl();
    $url = $baseUrl . "/apps/{$appId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    PaymentHoodHandler::safeLogModuleCall('gateway_webhook_registration_completed', [
        'appId' => $appId,
        'url' => $url,
        'payload' => $payload
    ], [
        'httpCode' => $httpCode,
        'success' => ($httpCode >= 200 && $httpCode < 300)
    ]);

    return $httpCode >= 200 && $httpCode < 300;
}

function paymenthood_saveGatewaySetting($gateway, $setting, $value)
{
    try {
        Capsule::connection()->transaction(function () use ($gateway, $setting, $value) {
            $rows = Capsule::table('tblpaymentgateways')
                ->where('gateway', $gateway)
                ->whereRaw("TRIM(LOWER(setting)) = ?", [strtolower($setting)])
                ->get();

            $keepId = null;
            foreach ($rows as $row) {
                if ($keepId === null) {
                    $keepId = $row->id;
                } else {
                    Capsule::table('tblpaymentgateways')->where('id', $row->id)->delete();
                }
            }

            if ($keepId !== null) {
                Capsule::table('tblpaymentgateways')->where('id', $keepId)->update(['value' => $value]);
            } else {
                Capsule::table('tblpaymentgateways')->insert([
                    'gateway' => $gateway,
                    'setting' => $setting,
                    'value' => $value,
                ]);
            }
        });
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway_save_setting_error', [
            'gateway' => $gateway,
            'setting' => $setting
        ], [
            'error' => $e->getMessage()
        ]);
    }
}

function paymenthood_link($params)
{
    try {
        PaymentHoodHandler::safeLogModuleCall('gateway_link_invoked', [
            'invoiceId' => $params['invoiceid'] ?? null
        ], []);
        return paymenthoodHandler::handleInvoice($params);
    } catch (\Throwable $ex) {
        PaymentHoodHandler::safeLogModuleCall('gateway_link_error', [
            'invoiceId' => $params['invoiceid'] ?? null
        ], [
            'error' => $ex->getMessage()
        ]);
        // Stay on the same invoice page and show message
        return '<div class="alert alert-danger">Error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
    }
}

function paymenthood_appendInvoiceNote($invoiceId, $text)
{
    try {
        $existing = Capsule::table('tblinvoices')
            ->where('id', (int) $invoiceId)
            ->value('notes');

        $existing = is_string($existing) ? trim($existing) : '';
        $newNotes = $existing !== '' ? ($existing . "\n" . $text) : $text;

        Capsule::table('tblinvoices')
            ->where('id', (int) $invoiceId)
            ->update(['notes' => $newNotes]);

        PaymentHoodHandler::safeLogModuleCall('gateway_invoice_note_appended', [
            'invoiceId' => $invoiceId
        ], [
            'appendedTextLength' => strlen($text)
        ]);

        return true;
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway_invoice_note_append_error', [
            'invoiceId' => $invoiceId
        ], [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

function paymenthood_refund($params)
{
    try {
        // WHMCS Fields
        $invoiceId = (int) $params['invoiceid'];
        $transactionId = $params['transid']; // PaymentHood paymentId
        $refundAmount = $params['amount'];
        $currency = $params['currency'];
        $clientId = (int) $params['clientdetails']['userid'];

        // Gateway Credentials
        $credentials = PaymentHoodHandler::getGatewayCredentials();
        $appId = $credentials['appId'];
        $token = $credentials['token'];

        PaymentHoodHandler::safeLogModuleCall('gateway_refund_initiated', [
            'invoiceId' => $invoiceId,
            'transactionId' => $transactionId,
            'amount' => $refundAmount,
            'currency' => $currency,
            'clientId' => $clientId
        ], []);

        // 1. Check if invoice was paid by PaymentHood
        $invoicePaymentMethod = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('paymentmethod');

        if ($invoicePaymentMethod !== paymenthood_GATEWAY) {
            PaymentHoodHandler::safeLogModuleCall('gateway_refund_invalid_gateway', [
                'invoiceId' => $invoiceId
            ], [
                'expected' => paymenthood_GATEWAY,
                'actual' => $invoicePaymentMethod,
                'error' => 'Invoice was not paid using PaymentHood gateway'
            ]);

            return [
                'status' => 'error',
                'rawdata' => 'Invoice was not paid using PaymentHood gateway'
            ];
        }

        // Safety check
        if (!$transactionId) {
            return [
                'status' => 'error',
                'rawdata' => 'Missing PaymentHood paymentId (transid)'
            ];
        }

        // 2. Get payment by referenceId to check canRefund status
        $getUrl = PaymentHoodHandler::paymenthood_getPaymentBaseUrl()
            . "/apps/{$appId}/payments/referenceId:{$invoiceId}";

        $ch = curl_init($getUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $paymentData = json_decode($response, true) ?: [];
        $paymentData['_httpCode'] = $httpCode;

        PaymentHoodHandler::safeLogModuleCall('gateway_refund_get_payment', [
            'invoiceId' => $invoiceId,
            'url' => $getUrl
        ], [
            'httpCode' => $paymentData['_httpCode'] ?? null,
            'paymentId' => $paymentData['paymentId'] ?? null,
            'canRefund' => $paymentData['canRefund'] ?? null,
            'status' => $paymentData['status'] ?? null
        ]);

        if (empty($paymentData['paymentId'])) {
            return [
                'status' => 'error',
                'rawdata' => 'Payment not found in PaymentHood'
            ];
        }

        $paymentId = $paymentData['paymentId'];
        $canRefund = $paymentData['canRefund'] ?? false;

        // 3. Process refund based on canRefund flag
        if ($canRefund) {
            // Use Payment API refund endpoint (actual refund through provider)
            $refundUrl = PaymentHoodHandler::paymenthood_getPaymentBaseUrl()
                . "/apps/{$appId}/payments/{$paymentId}/refund";

            $ch = curl_init($refundUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ]);
            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $response = json_decode($apiResponse, true) ?: [];
            $response['_httpCode'] = $httpCode;

            PaymentHoodHandler::safeLogModuleCall('gateway_refund_execute', [
                'paymentId' => $paymentId,
                'url' => $refundUrl,
                'method' => 'POST'
            ], [
                'httpCode' => $response['_httpCode'] ?? null,
                'status' => $response['status'] ?? null,
                'refundId' => $response['refundId'] ?? null
            ]);

            // Check if refund was successful
            $refundStatus = $response['paymentState'] ?? '';
            if ($refundStatus === 'Refunded' || $refundStatus === 'Refunding') {
                $refundTransactionId = $response['refundId'] ?? ('refund_' . $paymentId);

                // Append note to invoice about the refund
                paymenthood_appendInvoiceNote($invoiceId, 'Refund processed via PaymentHood (Transaction: ' . $refundTransactionId . '). Amount: $' . number_format($refundAmount, 2) . ' ' . $currency . '. Please manually update invoice status if needed.');

                PaymentHoodHandler::safeLogModuleCall('gateway_refund_success', [
                    'invoiceId' => $invoiceId,
                    'paymentId' => $paymentId,
                    'amount' => $refundAmount
                ], [
                    'refundId' => $refundTransactionId,
                    'status' => $refundStatus
                ]);

                // WHMCS handles refund recording automatically
                return [
                    'status' => 'success',
                    'transid' => $refundTransactionId,
                    'rawdata' => $response
                ];
            }

            throw new \Exception('Refund failed. Status: ' . $refundStatus);

        } else {
            // Use App API mark as refund endpoint (manual/external refund)
            $markRefundUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl()
                . "/apps/{$appId}/payments/{$paymentId}/mark-as-refund";

            $markRefundPayload = [
                'amount' => $refundAmount,
                'currency' => $currency,
                'reason' => 'WHMCS Refund Request - Invoice #' . $invoiceId,
            ];

            $ch = curl_init($markRefundUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($markRefundPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ]);
            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $response = json_decode($apiResponse, true) ?: [];
            $response['_httpCode'] = $httpCode;

            PaymentHoodHandler::safeLogModuleCall('gateway_refund_mark_as_refund', [
                'paymentId' => $paymentId,
                'url' => $markRefundUrl,
                'method' => 'POST',
                'amount' => $markRefundPayload['amount'],
                'currency' => $markRefundPayload['currency']
            ], [
                'httpCode' => $response['_httpCode'] ?? null,
                'success' => ($response['_httpCode'] >= 200 && $response['_httpCode'] < 300)
            ]);

            // Check if mark as refund was successful
            $httpCode = $response['_httpCode'] ?? 0;
            if ($httpCode >= 200 && $httpCode < 300) {
                $refundTransactionId = 'manual_refund_' . $paymentId;

                // Append note to invoice about the manual refund
                paymenthood_appendInvoiceNote($invoiceId, 'MARKED AS REFUNDED via PaymentHood (Transaction: ' . $refundTransactionId . '). Amount: $' . number_format($refundAmount, 2) . ' ' . $currency . '. WARNING: Money was NOT automatically returned to customer - YOU MUST REFUND MANUALLY. Please update invoice status after manual refund is completed.');

                PaymentHoodHandler::safeLogModuleCall('gateway_refund_success', [
                    'invoiceId' => $invoiceId,
                    'paymentId' => $paymentId,
                    'amount' => $refundAmount
                ], [
                    'refundId' => $refundTransactionId,
                    'manualRefund' => true
                ]);

                // WHMCS handles refund recording automatically
                return [
                    'status' => 'success',
                    'transid' => $refundTransactionId,
                    'rawdata' => $response
                ];
            }

            throw new \Exception('Mark as refund failed. HTTP Code: ' . $httpCode);
        }

    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall(
            'gateway_refund_exception',
            [
                'invoiceId' => $params['invoiceid'] ?? null,
                'transactionId' => $params['transid'] ?? null,
                'amount' => $params['amount'] ?? null
            ],
            [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        return [
            'status' => 'error',
            'rawdata' => $e->getMessage()
        ];
    }
}
