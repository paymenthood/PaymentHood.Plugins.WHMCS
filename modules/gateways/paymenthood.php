<?php
use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

$handlerPath = __DIR__ . '/../addons/paymenthood/paymenthoodhandler.php';
if (!file_exists($handlerPath)) {
    die('PaymentHood installation incomplete: Missing required file at modules/addons/paymenthood/paymenthoodhandler.php. Please ensure ALL plugin files and directories are uploaded to your WHMCS installation.');
}
require_once $handlerPath;

// Verify the class was loaded successfully
if (!class_exists('PaymentHoodHandler')) {
    die('PaymentHood installation error: The file modules/addons/paymenthood/paymenthoodhandler.php exists but the PaymentHoodHandler class could not be loaded. The file may be corrupted or incomplete. Please re-upload the file ensuring it is transferred in binary mode (not ASCII mode in FTP) and verify the file size matches the original.');
}

// Load WHMCS functions if not already loaded
if (!function_exists('addInvoiceRefund')) {
    require_once ROOTDIR . '/includes/invoicefunctions.php';
}
if (!function_exists('localAPI')) {
    require_once ROOTDIR . '/includes/functions.php';
}

if (!defined('paymenthood_GATEWAY')) {
    define('paymenthood_GATEWAY', 'paymenthood');
}

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

    $inactiveState = PaymentHoodHandler::refreshAppInactiveState();
    if (!empty($inactiveState['isInactive'])) {
        $config['licenseStatus'] = [
            'FriendlyName' => 'License Status',
            'Type' => 'system',
            'Description' => paymenthood_getInactiveLicenseNotice($inactiveState),
        ];
    }

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

    // Add checkout message field
    $config['checkoutMessage'] = [
        'FriendlyName' => 'Checkout Message',
        'Type' => 'textarea',
        'Rows' => '3',
        'Description' => 'Optional message to display to customers on the checkout page above payment methods. Supports HTML.',
        'Default' => 'To proceed with your payment, please click "Complete Order".<br>You will then be redirected to PaymentHood, where you can choose your preferred payment method or gateway.',
    ];

    return $config;
}

function paymenthood_getInactiveLicenseNotice(array $inactiveState)
{
    $appId = isset($inactiveState['appId']) ? trim((string) $inactiveState['appId']) : '';
    $message = isset($inactiveState['message']) ? trim((string) $inactiveState['message']) : '';
    $detectedAt = isset($inactiveState['detectedAt']) ? trim((string) $inactiveState['detectedAt']) : '';
    $renewUrl = PaymentHoodHandler::getManageLicensesUrl($appId);

    $html = '<div style="padding:12px 14px;border:1px solid #f5c2c7;background:#fff3f3;color:#842029;border-radius:6px;">';
    $html .= '<div style="font-weight:bold;margin-bottom:6px;">PaymentHood license expired</div>';
    $html .= '<div>Your PaymentHood app is inactive. Renew the license to restore checkout and admin actions.</div>';

    if ($renewUrl) {
        $html .= '<div style="margin-top:12px;">'
            . '<a href="' . htmlspecialchars($renewUrl) . '" target="_blank" rel="noopener" '
            . 'style="padding:8px 16px;background:#dc3545;color:white;border-radius:4px;text-decoration:none;display:inline-block;">'
            . 'Renew PaymentHood License'
            . '</a></div>';
    }

    $html .= '</div>';

    return $html;
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

            // Renewal succeeded; clear any previously stored inactive-license notice.
            PaymentHoodHandler::clearAppInactiveState();

            // Create custom field for payment provider storage
            paymenthood_createCustomFields();

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
                ->orderBy('id', 'desc')
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

function paymenthood_createCustomFields()
{
    try {
        $now = date('Y-m-d H:i:s');

        $fields = [
            [
                'fieldname' => 'paymenthood_provider',
                'description' => 'PaymentHood Payment Provider',
            ],
            [
                'fieldname' => 'paymenthood_app_id',
                'description' => 'PaymentHood App ID',
            ],
            [
                'fieldname' => 'paymenthood_payment_id',
                'description' => 'PaymentHood Payment ID',
            ],
        ];

        foreach ($fields as $field) {
            $fieldName = $field['fieldname'];
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'invoice')
                ->where('fieldname', $fieldName)
                ->exists();

            if ($exists) {
                continue;
            }

            Capsule::table('tblcustomfields')->insert([
                'type' => 'invoice',
                'fieldname' => $fieldName,
                'fieldtype' => 'text',
                'description' => $field['description'],
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => 'on',
                'showinvoice' => '',
                'sortorder' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            PaymentHoodHandler::safeLogModuleCall('gateway_custom_field_created', [
                'fieldname' => $fieldName,
            ], []);
        }
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway_custom_field_error', [], [
            'error' => $e->getMessage()
        ]);
    }
}

function paymenthood_link($params)
{
    try {
        if (defined('WHMCS_MAIL') && WHMCS_MAIL) {
            $systemUrl = rtrim((string) ($params['systemurl'] ?? PaymentHoodHandler::getSystemUrl()), '/');
            $invoiceId = (int) ($params['invoiceid'] ?? 0);
            $invoiceUrl = $invoiceId > 0
                ? $systemUrl . '/viewinvoice.php?id=' . $invoiceId
                : $systemUrl . '/clientarea.php?action=invoices';

            return '<a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">Pay with PaymentHood</a>';
        }

        return paymenthoodHandler::handleInvoice($params);
    } catch (\Throwable $ex) {
        PaymentHoodHandler::safeLogModuleCall('gateway_link_error', [
            'invoiceId' => $params['invoiceid'] ?? null
        ], [
            'error' => $ex->getMessage()
        ]);

        if ($ex instanceof PaymentHoodAppInactiveException || PaymentHoodHandler::isAppInactiveError($ex)) {
            $inactiveAppId = $ex instanceof PaymentHoodAppInactiveException ? $ex->getAppId() : null;
            return PaymentHoodHandler::renderAppInactiveError($inactiveAppId, PaymentHoodHandler::extractAppInactiveErrorMessage($ex));
        }

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

/**
 * POST to a PaymentHood refund endpoint.
 *
 * Both /refund and /mark-as-refund take every argument as a query parameter
 * and no request body, so this only needs the fully-built URL.
 *
 * @return array{body: string, httpCode: int, data: array}
 */
function paymenthood_refundApiPost($url, $token)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = $body === false ? '' : (string) $body;
    $data = json_decode($body, true);

    return [
        'body'     => $body,
        'httpCode' => $httpCode,
        'data'     => is_array($data) ? $data : [],
    ];
}

/**
 * Pull the typed error envelope out of a PaymentHood API response.
 *
 * Failures come back as {"TypeName":..., "TypeFullName":..., "Message":...,
 * "Data":{}} rather than a plain status, so a bare HTTP code says nothing about
 * what went wrong.
 */
function paymenthood_refundApiError(array $response)
{
    $pick = function (array $keys) use ($response) {
        foreach ($keys as $key) {
            if (isset($response[$key]) && $response[$key] !== '' && $response[$key] !== []) {
                return $response[$key];
            }
        }
        return null;
    };

    return array_filter([
        'typeName'     => $pick(['TypeName', 'typeName']),
        'typeFullName' => $pick(['TypeFullName', 'typeFullName']),
        'message'      => $pick(['Message', 'message', 'error', 'detail', 'title']),
        'data'         => $pick(['Data', 'data']),
        'errors'       => $pick(['Errors', 'errors']),
    ], function ($value) {
        return $value !== null;
    });
}

/**
 * Human-readable one-liner describing an API failure, for the thrown exception
 * and therefore for the message WHMCS shows the admin.
 */
function paymenthood_refundErrorSummary($httpCode, array $response, $rawBody)
{
    $error = paymenthood_refundApiError($response);

    $parts = [];
    if (!empty($error['typeName'])) {
        $parts[] = (string) $error['typeName'];
    }
    if (!empty($error['message']) && (empty($error['typeName']) || strpos((string) $error['message'], (string) $error['typeName']) === false)) {
        $parts[] = (string) $error['message'];
    }
    if (!empty($error['errors'])) {
        $parts[] = 'errors=' . json_encode($error['errors']);
    }

    if ($parts === []) {
        $raw = trim((string) $rawBody);
        $parts[] = $raw === '' ? 'empty response body' : substr($raw, 0, 300);
    }

    return 'HTTP ' . (int) $httpCode . ' - ' . implode(': ', $parts);
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

        // Authenticator code the operator typed into the refund form, if any.
        // Both endpoints accept it as an optional `otpCode` query parameter.
        $otpCode = PaymentHoodHandler::readRefundOtpCode();

        // 3. Build the request. Both endpoints live on the App API and take
        //    their arguments as query parameters, with no request body.
        if ($canRefund) {
            // Real refund through the provider.
            $action = 'refund';
            $path = "/apps/{$appId}/payments/{$paymentId}/refund";
            $query = [];
        } else {
            // Book-only refund; the money is returned to the customer by hand.
            $action = 'mark-as-refund';
            $path = "/apps/{$appId}/payments/{$paymentId}/mark-as-refund";
            // `description` is REQUIRED on this endpoint.
            $query = ['description' => 'WHMCS Refund Request - Invoice #' . $invoiceId];
        }

        if ($otpCode !== '') {
            $query['otpCode'] = $otpCode;
        }

        $requestUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl() . $path;
        if ($query !== []) {
            $requestUrl .= '?' . http_build_query($query);
        }

        $call = paymenthood_refundApiPost($requestUrl, $token);
        $response = $call['data'];
        $httpCode = $call['httpCode'];
        $response['_httpCode'] = $httpCode;

        PaymentHoodHandler::safeLogModuleCall('gateway_refund_execute', [
            'paymentId' => $paymentId,
            'action' => $action,
            // Never log the URL directly: it carries the operator's otpCode.
            'url' => PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl() . $path,
            'method' => 'POST',
            'otpSupplied' => $otpCode !== '',
        ], [
            'httpCode' => $httpCode,
            'paymentState' => $response['paymentState'] ?? null,
            'refundId' => $response['refundId'] ?? null,
            // The API reports failures as a typed envelope in the body. Without
            // these two the log only ever showed a bare status code.
            'apiError' => paymenthood_refundApiError($response),
            'rawResponse' => substr($call['body'], 0, 2000),
        ]);

        // Separate entry for non-2xx so failures are findable without reading
        // every successful refund call.
        if ($httpCode < 200 || $httpCode >= 300) {
            PaymentHoodHandler::safeLogModuleCall('gateway_refund_api_error', [
                'invoiceId' => $invoiceId,
                'paymentId' => $paymentId,
                'action' => $action,
                'otpSupplied' => $otpCode !== '',
            ], [
                'httpCode' => $httpCode,
                'summary' => paymenthood_refundErrorSummary($httpCode, $response, $call['body']),
                'apiError' => paymenthood_refundApiError($response),
                'rawResponse' => substr($call['body'], 0, 2000),
            ]);
        }

        // 4. Two-factor gate. Both endpoints answer with a typed exception
        //    envelope instead of a plain status, so this must be checked
        //    before any success/failure interpretation.
        $twoFactor = PaymentHoodHandler::detectTwoFactorError($call['body']);

        if ($twoFactor === PaymentHoodHandler::REFUND_2FA_NEEDS_ACTIVATION) {
            $message = 'Two-factor authentication is not enabled on your PaymentHood account. '
                . 'You must activate 2FA in the PaymentHood console before you can issue refunds. '
                . 'Contact your administrator, or open the PaymentHood console at '
                . PaymentHoodHandler::paymenthood_ConsoleUrl() . ' to enable it, then retry this refund. '
                . 'No money has been moved.';

            PaymentHoodHandler::flagRefund2fa(
                PaymentHoodHandler::REFUND_2FA_NEEDS_ACTIVATION,
                $invoiceId,
                $message
            );

            PaymentHoodHandler::safeLogModuleCall('gateway_refund_2fa_not_activated', [
                'invoiceId' => $invoiceId,
                'paymentId' => $paymentId,
                'action' => $action,
            ], ['httpCode' => $httpCode]);

            return ['status' => 'error', 'rawdata' => $message];
        }

        if ($twoFactor === PaymentHoodHandler::REFUND_2FA_INVALID_CODE) {
            $message = $otpCode === ''
                ? 'This refund requires a two-factor authentication code. Enter the 6-digit code from '
                    . 'your Google Authenticator app and submit the refund again. No money has been moved.'
                : 'The two-factor authentication code was rejected — it is incorrect or has expired. '
                    . 'Enter a fresh 6-digit code from your Google Authenticator app and try again. '
                    . 'No money has been moved.';

            PaymentHoodHandler::flagRefund2fa(
                PaymentHoodHandler::REFUND_2FA_INVALID_CODE,
                $invoiceId,
                $message
            );

            PaymentHoodHandler::safeLogModuleCall('gateway_refund_2fa_required', [
                'invoiceId' => $invoiceId,
                'paymentId' => $paymentId,
                'action' => $action,
                'otpSupplied' => $otpCode !== '',
            ], ['httpCode' => $httpCode]);

            return ['status' => 'error', 'rawdata' => $message];
        }

        // 5. Interpret the result.
        if ($canRefund) {
            $refundStatus = $response['paymentState'] ?? '';

            if ($refundStatus === 'Refunded' || $refundStatus === 'Refunding') {
                $refundTransactionId = $response['refundId'] ?? ('refund_' . $paymentId);

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

            throw new \Exception('Refund failed. ' . ($refundStatus !== ''
                ? 'Payment state: ' . $refundStatus
                : paymenthood_refundErrorSummary($httpCode, $response, $call['body'])));
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $refundTransactionId = 'manual_refund_' . $paymentId;

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

        throw new \Exception('Mark as refund failed. '
            . paymenthood_refundErrorSummary($httpCode, $response, $call['body']));

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
