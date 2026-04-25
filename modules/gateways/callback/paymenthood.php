<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../addons/paymenthood/paymenthoodhandler.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('PAYMENTHOOD_GATEWAY', 'paymenthood');

function paymenthood_resolveEmailTemplate(array $preferredNames, callable $fallbackMatcher): array
{
    $result = [
        'name' => null,
        'candidates' => [],
    ];

    try {
        $existingNames = Capsule::table('tblemailtemplates')
            ->pluck('name')
            ->filter(function ($name) {
                return is_string($name) && trim($name) !== '';
            })
            ->map(function ($name) {
                return trim((string) $name);
            })
            ->values()
            ->all();

        $result['candidates'] = $existingNames;

        foreach ($preferredNames as $preferredName) {
            foreach ($existingNames as $existingName) {
                if (strcasecmp($existingName, $preferredName) === 0) {
                    $result['name'] = $existingName;
                    return $result;
                }
            }
        }

        foreach ($existingNames as $existingName) {
            if ($fallbackMatcher($existingName)) {
                $result['name'] = $existingName;
                return $result;
            }
        }
    } catch (\Throwable $e) {
        $result['candidates'] = [];
    }

    return $result;
}

function paymenthood_resolveRecurringFailureTemplate(): array
{
    return paymenthood_resolveEmailTemplate(
        [
            'Invoice Payment Failed',
            'Credit Card Payment Failed',
        ],
        function ($existingName) {
            $lowerName = strtolower((string) $existingName);
            return strpos($lowerName, 'payment') !== false && strpos($lowerName, 'failed') !== false;
        }
    );
}

function paymenthood_resolveCancelledFailureTemplate(): array
{
    return paymenthood_resolveEmailTemplate(
        [
            'Order Cancelled',
            'Order Cancellation Confirmation',
            'Invoice Cancelled',
            'Payment Cancelled',
        ],
        function ($existingName) {
            $lowerName = strtolower((string) $existingName);
            $hasCancel = strpos($lowerName, 'cancel') !== false;
            $hasOrderOrInvoice = strpos($lowerName, 'order') !== false || strpos($lowerName, 'invoice') !== false;
            return $hasCancel && $hasOrderOrInvoice;
        }
    );
}

function paymenthood_sendInvoiceEmail(int $invoiceId, array $template): array
{
    $result = [
        'sent' => false,
        'method' => null,
        'error' => null,
        'details' => [],
    ];

    if ($invoiceId <= 0) {
        $result['error'] = 'Invalid invoice ID';
        return $result;
    }

    $templateName = $template['name'];
    $result['details']['availableTemplates'] = $template['candidates'];
    $result['details']['templateName'] = $templateName;

    if (!$templateName) {
        $result['error'] = 'No payment failure email template found in tblemailtemplates';
        return $result;
    }

    if (!function_exists('sendMessage') && defined('ROOTDIR')) {
        $candidateFiles = [
            ROOTDIR . '/includes/functions.php',
            ROOTDIR . '/includes/clientfunctions.php',
        ];

        foreach ($candidateFiles as $candidateFile) {
            if (is_file($candidateFile)) {
                require_once $candidateFile;
                if (function_exists('sendMessage')) {
                    break;
                }
            }
        }
    }

    if (function_exists('sendMessage')) {
        try {
            $sendMessageResult = sendMessage($templateName, $invoiceId);
            $result['sent'] = (bool) $sendMessageResult;
            $result['method'] = 'sendMessage';
            $result['details']['returnValue'] = $sendMessageResult;

            if ($result['sent']) {
                return $result;
            }

            $result['error'] = 'sendMessage returned false';
        } catch (\Throwable $e) {
            $result['method'] = 'sendMessage';
            $result['error'] = $e->getMessage();
        }
    }

    try {
        $adminUser = Capsule::table('tbladmins')
            ->where('disabled', 0)
            ->orderBy('id', 'asc')
            ->value('username');

        $result['details']['adminUser'] = $adminUser;

        if (!$adminUser) {
            if ($result['error'] === null) {
                $result['error'] = 'No active admin found for SendEmail fallback';
            }
            return $result;
        }

        $emailResult = localAPI('SendEmail', [
            'messagename' => $templateName,
            'id' => $invoiceId,
        ], $adminUser);

        $result['sent'] = (($emailResult['result'] ?? '') === 'success');
        $result['method'] = 'localAPI';
        $result['details']['localApiResult'] = $emailResult;

        if (!$result['sent']) {
            $result['error'] = $emailResult['message'] ?? 'localAPI SendEmail failed';
        }
    } catch (\Throwable $e) {
        $result['method'] = 'localAPI';
        $result['error'] = $e->getMessage();
    }

    return $result;
}

function paymenthood_sendRecurringFailureEmail(int $invoiceId): array
{
    return paymenthood_sendInvoiceEmail($invoiceId, paymenthood_resolveRecurringFailureTemplate());
}

function paymenthood_sendCancelledFailureEmail(int $invoiceId): array
{
    return paymenthood_sendInvoiceEmail($invoiceId, paymenthood_resolveCancelledFailureTemplate());
}

function paymenthood_isRecurringRenewalInvoice(int $invoiceId): bool
{
    if ($invoiceId <= 0) {
        return false;
    }

    try {
        return Capsule::table('tblinvoices as i')
            ->join('tblinvoiceitems as ii', 'ii.invoiceid', '=', 'i.id')
            ->join('tblhosting as h', 'ii.relid', '=', 'h.id')
            ->where('i.id', $invoiceId)
            ->where('ii.type', 'Hosting')
            ->whereNotIn('h.billingcycle', ['One Time', 'Free', ''])
            ->whereColumn('i.duedate', '=', 'h.nextduedate')
            // A new subscription has a Pending hosting record; a renewal has an Active/Suspended one.
            // Excluding Pending ensures new subscription failures are treated as final (cancel invoice),
            // not as retryable renewal failures.
            ->whereNotIn('h.domainstatus', ['Pending', ''])
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log raw input first
    $rawInput = file_get_contents('php://input');

    try {
        $json = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            PaymentHoodHandler::safeLogModuleCall('callback_webhook_json_parse_error', [], [
                'error' => json_last_error_msg(),
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        $referenceId = $json['payment']['referenceId'] ?? null;
        if (!$referenceId) {
            PaymentHoodHandler::safeLogModuleCall('callback_webhook_missing_reference', [], [
                'error' => 'Missing referenceId in payload'
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Missing referenceId', 'received' => $json]);
            exit;
        }

        processPaymenthoodCallback($referenceId, true);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
        exit;

    } catch (Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('callback_webhook_post_exception', [], [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Internal error: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $referenceId = $_GET['invoiceid'] ?? null;
    if (!$referenceId) {
        die('Missing invoiceId');
    }

    processPaymenthoodCallback($referenceId, false);
    exit;
}

function processPaymenthoodCallback(string $referenceId, bool $validateAuthorization)
{
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];
    $token = $credentials['token'];
    $webhookToken = $credentials['webhookToken'];

    if (!$appId || !$token || !$webhookToken) {
        die('Payment gateway not configured');
    }

    if ($validateAuthorization && !validatePaymenthoodWebhookToken($webhookToken)) {
        http_response_code(401);
        die('Unauthorized');
    }

    $invoiceId = (int) $referenceId;

    // Prepare API call
    $url = PaymentHoodHandler::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/referenceId:$referenceId";

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain',
            "Authorization: Bearer $token"
        ]
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!$response || $httpCode >= 400) {
        die('Error communicating with payment gateway');
    }

    $data = json_decode($response, true);
    $paymentState = $data['paymentState'] ?? 'unknown';
    $transactionId = $data['paymentId'] ?? 'N/A'; // fallback

    // Extract fee from feeBreakdown (appFee + providerFee)
    $totalFee = PaymentHoodHandler::extractTotalFee($data);

    // Extract and store provider name if available
    $providerName = null;
    if (isset($data['payInfo']['paymentProfile']['paymentProvider']['provider'])) {
        $providerName = $data['payInfo']['paymentProfile']['paymentProvider']['provider'];
    }

    // Store PaymentHood fields on invoice custom fields (independent of paymentState)
    // This is what the admin report uses to build the Details URL.
    paymenthood_upsertInvoicePaymenthoodCustomFields(
        $invoiceId,
        (string) $appId,
        !empty($data['paymentId']) ? (string) $data['paymentId'] : '',
        !empty($providerName) ? (string) $providerName : ''
    );

    // Log the resolved payment state so the full lifecycle is visible in the gateway log.
    $callbackSource = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'webhook (POST)' : 'browser return (GET)';
    PaymentHoodHandler::safeLogModuleCall('callback_payment_state_received', [
        'invoiceId' => $invoiceId,
        'source'    => $callbackSource,
        '_note'     => "PaymentHood callback received for invoice #$invoiceId via $callbackSource. Payment state has been resolved from the PaymentHood API.",
    ], [
        'paymentState'  => $paymentState,
        'transactionId' => $transactionId !== 'N/A' ? $transactionId : null,
        'amount'        => $data['amount'] ?? null,
        'provider'      => $providerName,
        '_result'       => "Payment is currently in '$paymentState' state.",
    ]);

    // Store provider in transaction description if we have both transaction ID and provider
    if ($providerName && $transactionId !== 'N/A') {
        try {
            // Update transaction description
            $result = localAPI('UpdateTransaction', [
                'transid' => $transactionId,
                'description' => "PaymentHood - {$providerName}"
            ]);


        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_provider_store_error', [
                'invoiceId' => $invoiceId,
                'provider' => $providerName
            ], [
                'error' => $e->getMessage()
            ]);
        }
    }

    // Update invoice notes:
    // - Always store Payment Provider when available (independent of paymentState)
    // - Also store Payment provider state once
    try {
        $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        $notes = $invoiceData['notes'] ?? '';
        $notes = is_string($notes) ? $notes : '';

        $newNotes = $notes;
        $changed = false;

        if ($providerName) {
            // Only add provider note if it doesn't already exist
            if (stripos($newNotes, 'Payment Provider:') === false) {
                $line = "Payment Provider: {$providerName}";
                $newNotes = trim($newNotes);
                $newNotes = $newNotes !== '' ? ($newNotes . "\n" . $line) : $line;
                $changed = true;
            }
        }

        if (stripos($newNotes, 'Payment provider state') === false) {
            $line = "Payment provider state: {$paymentState}";
            $newNotes = trim($newNotes);
            $newNotes = $newNotes !== '' ? ($newNotes . "\n" . $line) : $line;
            $changed = true;
        }

        if ($changed) {
            $results = localAPI('UpdateInvoice', [
                'invoiceid' => $invoiceId,
                'notes' => $newNotes,
            ]);


        }
    } catch (Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('callback_invoice_notes_update_error', [
            'invoiceId' => $invoiceId,
        ], [
            'error' => $e->getMessage(),
        ]);
    }

    // decide for invoice based on payment provider state
    if ($paymentState === 'Captured') {
        // Clear the session redirect cache for this invoice so a future payment
        // attempt (e.g. after expiry or on a new invoice) gets a fresh hosted-page.
        unset($_SESSION['paymenthood_redirect_' . $invoiceId]);

        // Payment Success
        try {
            $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

            if ($invoiceData['status'] === 'Paid') {
                // Invoice was already paid (e.g. by credit) before this webhook arrived.
                // This should not happen because the client-area hook blocks Apply Credit
                // when a PaymentHood payment exists for an Unpaid invoice. If it does
                // happen anyway, log a prominent warning for manual admin action.
                PaymentHoodHandler::safeLogModuleCall('callback_captured_invoice_already_paid', [
                    'invoiceId'     => $invoiceId,
                    'transactionId' => $transactionId !== 'N/A' ? $transactionId : null,
                    'source'        => $callbackSource,
                    '_note'         => "ATTENTION: Invoice #$invoiceId was already Paid when PaymentHood reported Captured. The customer may have been double-charged. Manual review and refund required via PaymentHood console.",
                ], [
                    'amount'  => $data['amount'] ?? null,
                    '_result' => 'No automated action taken. Admin must review and issue refund manually if needed.',
                ]);
            } else {
                // Record payment through WHMCS. addInvoicePayment() marks the invoice Paid,
                // records the transaction, and triggers WHMCS's built-in email hooks
                // (Invoice Payment Confirmation) — same as Stripe and all official gateways.
                // Only log to Gateway Log once — via the authoritative webhook (POST).
                // The browser return (GET) for the same payment must not create a duplicate entry.
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    logTransaction(PAYMENTHOOD_GATEWAY, $data, 'Successful');
                }
                PaymentHoodHandler::recordInvoicePayment(
                    (int) $invoiceId,
                    $transactionId,
                    $data['amount'],
                    $totalFee,
                    PAYMENTHOOD_GATEWAY
                );

                PaymentHoodHandler::safeLogModuleCall('callback_payment_recorded', [
                    'invoiceId'     => $invoiceId,
                    'transactionId' => $transactionId,
                    'amount'        => $data['amount'],
                    '_note'         => "Payment for invoice #$invoiceId has been captured by PaymentHood (state: Captured). Recording transaction #$transactionId in WHMCS and marking invoice as Paid.",
                ], [
                    'fee'      => $totalFee,
                    'gateway'  => PAYMENTHOOD_GATEWAY,
                    '_result'  => 'Invoice marked as Paid. Transaction recorded in WHMCS accounts.',
                ]);
            }
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_payment_record_error', [
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'amount' => $data['amount']
            ], [
                'error' => $e->getMessage()
            ]);
        }

        // Accept any pending order linked to this invoice so WHMCS sends
        // Order Confirmation (customer) and New Order Notification (admin) emails.
        try {
            $orderId = Capsule::table('tblorders')
                ->where('invoiceid', $invoiceId)
                ->where('status', 'Pending')
                ->value('id');

            if ($orderId) {
                $acceptResult = localAPI('AcceptOrder', [
                    'orderid'   => $orderId,
                    'sendemail' => false, // addInvoicePayment() already sent the payment confirmation
                    'autosetup' => true,
                ]);

                $orderAccepted = ($acceptResult['result'] ?? '') === 'success';
                PaymentHoodHandler::safeLogModuleCall('callback_order_accepted', [
                    'invoiceId' => $invoiceId,
                    'orderId'   => $orderId,
                    '_note'     => "Payment confirmed for invoice #$invoiceId. Accepting pending order #$orderId to trigger service provisioning and send order confirmation emails.",
                ], [
                    'success'  => $orderAccepted ? 1 : 0,
                    'error'    => $acceptResult['message'] ?? null,
                    '_result'  => $orderAccepted
                        ? "Order #$orderId accepted. Services are being provisioned and confirmation emails sent."
                        : 'Order acceptance failed: ' . ($acceptResult['message'] ?? 'Unknown error'),
                ]);
            }
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_order_accept_error', [
                'invoiceId' => $invoiceId,
            ], [
                'error' => $e->getMessage(),
            ]);
        }

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentsuccess=true";

            // Clear any WHMCS session data that might redirect to cart
            if (isset($_SESSION['cart'])) {
                unset($_SESSION['cart']);
            }
            if (isset($_SESSION['orderdetails'])) {
                unset($_SESSION['orderdetails']);
            }

            header("Location: $redirectUrl");
            exit;
        }

        http_response_code(200);
        echo "OK";

        exit;
    } elseif ($paymentState === 'Refunded') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            logTransaction(PAYMENTHOOD_GATEWAY, $data, 'Refunded');
        }
        // Payment Refunded - Use WHMCS API to update invoice
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Refunded',
                'notes' => 'Payment refunded via PaymentHood'
            ];
            $results = localAPI($command, $postData);


        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_invoice_refund_error', [
                'invoiceId' => $invoiceId
            ], [
                'error' => $e->getMessage()
            ]);
        }

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Location: " . PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentcancelled=true");
            exit;
        }

        http_response_code(200);
        echo "OK";
        exit;
    } elseif ($paymentState === 'Failed') {
        $isRecurringRenewalInvoice = paymenthood_isRecurringRenewalInvoice($invoiceId);

        // Guard against duplicate webhook processing — if the invoice is already in a
        // terminal state from a prior callback, skip all processing including the log.
        try {
            $currentInvoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
            $currentStatus = $currentInvoiceData['status'] ?? '';
            if (in_array($currentStatus, ['Cancelled', 'Paid', 'Refunded'], true)) {
                PaymentHoodHandler::safeLogModuleCall('callback_failed_duplicate_skipped', [
                    'invoiceId' => $invoiceId,
                    'source'    => $callbackSource,
                ], [
                    'currentStatus' => $currentStatus,
                    '_result'       => "Invoice #$invoiceId is already in '$currentStatus' state. Duplicate Failed webhook ignored.",
                ]);

                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    header("Location: " . PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentfailed=true");
                    exit;
                }
                http_response_code(200);
                echo "OK";
                exit;
            }
        } catch (Exception $e) {
            // If we can't check, proceed normally
        }

        // Log the failed transaction once — via the authoritative webhook (POST) only.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            logTransaction(PAYMENTHOOD_GATEWAY, $data, 'Failed');
        }

        // Clear session cache.
        unset($_SESSION['paymenthood_redirect_' . $invoiceId]);

        if ($isRecurringRenewalInvoice) {
            // Renewal failures should remain payable, so send the retry-oriented email
            // while the invoice is still Unpaid.
            $emailStatus = paymenthood_sendRecurringFailureEmail($invoiceId);

            PaymentHoodHandler::safeLogModuleCall('callback_payment_failed', [
                'invoiceId'     => $invoiceId,
                'transactionId' => $transactionId !== 'N/A' ? $transactionId : null,
                'isRecurringRenewalInvoice' => 1,
                '_note'         => "Recurring renewal payment failed for invoice #$invoiceId. A single retry-oriented email was attempted while the invoice remains Unpaid.",
            ], [
                'emailSent'   => $emailStatus['sent'] ? 1 : 0,
                'emailMethod' => $emailStatus['method'],
                'emailError'  => $emailStatus['error'],
                'emailDetails' => $emailStatus['details'],
                '_result'     => $emailStatus['sent']
                    ? 'Customer notified once. Renewal invoice remains Unpaid for retry; no order cancellation occurred.'
                    : 'Recurring failure email not sent. Renewal invoice remains Unpaid for retry.',
            ]);

            PaymentHoodHandler::safeLogModuleCall('callback_recurring_payment_failed_open_invoice', [
                'invoiceId' => $invoiceId,
                '_note' => "Invoice #$invoiceId is a recurring renewal invoice. Leaving it Unpaid so the customer or future automation can retry payment. No order status change is applied.",
            ], [
                '_result' => 'Renewal invoice left Unpaid. Existing service/order state is unchanged.',
            ]);

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentfailed=true";

                if (isset($_SESSION['cart'])) {
                    unset($_SESSION['cart']);
                }
                if (isset($_SESSION['orderdetails'])) {
                    unset($_SESSION['orderdetails']);
                }

                header("Location: $redirectUrl");
                exit;
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        // One-time checkout failures are final in this workflow: cancel invoice and
        // pending order first, then send a single cancelled/final-state email.

        // Cancel the invoice.
        try {
            localAPI('UpdateInvoice', [
                'invoiceid' => $invoiceId,
                'status'    => 'Cancelled',
                'notes'     => 'Payment failed via PaymentHood',
            ]);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_invoice_cancel_error', [
                'invoiceId' => $invoiceId,
            ], [
                'error' => $e->getMessage(),
            ]);
        }

        // Cancel the linked pending order.
        try {
            $failedOrderId = Capsule::table('tblorders')
                ->where('invoiceid', $invoiceId)
                ->where('status', 'Pending')
                ->value('id');

            if ($failedOrderId) {
                $cancelResult = localAPI('CancelOrder', ['orderid' => $failedOrderId]);

                PaymentHoodHandler::safeLogModuleCall('callback_order_cancelled_on_failure', [
                    'invoiceId' => $invoiceId,
                    'orderId'   => $failedOrderId,
                    '_note'     => "Payment failed — cancelling pending order #$failedOrderId linked to invoice #$invoiceId.",
                ], [
                    'success' => ($cancelResult['result'] ?? '') === 'success',
                    'error'   => $cancelResult['message'] ?? null,
                ]);
            }
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_order_cancel_error', [
                'invoiceId' => $invoiceId,
            ], [
                'error' => $e->getMessage(),
            ]);
        }

        $emailStatus = paymenthood_sendCancelledFailureEmail($invoiceId);

        PaymentHoodHandler::safeLogModuleCall('callback_payment_failed', [
            'invoiceId'     => $invoiceId,
            'transactionId' => $transactionId !== 'N/A' ? $transactionId : null,
            'isRecurringRenewalInvoice' => 0,
            '_note'         => "Initial checkout payment failed for invoice #$invoiceId. Invoice and pending order were cancelled first, then a single final-state email was attempted.",
        ], [
            'emailSent'   => $emailStatus['sent'] ? 1 : 0,
            'emailMethod' => $emailStatus['method'],
            'emailError'  => $emailStatus['error'],
            'emailDetails' => $emailStatus['details'],
            '_result'     => $emailStatus['sent']
                ? 'Customer notified once with cancelled/final-state messaging.'
                : 'Final-state cancellation email not sent. See emailError/emailDetails for the exact WHMCS response.',
        ]);

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentfailed=true";

            // Clear any WHMCS session data that might redirect to cart
            if (isset($_SESSION['cart'])) {
                unset($_SESSION['cart']);
            }
            if (isset($_SESSION['orderdetails'])) {
                unset($_SESSION['orderdetails']);
            }

            header("Location: $redirectUrl");
            exit;
        }

        http_response_code(200);
        echo "OK";
        exit;
    } else {
        // Still processing
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            logTransaction(PAYMENTHOOD_GATEWAY, $data, 'Pending');
        }
        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentpending=true";

            // Clear any WHMCS session data that might redirect to cart
            if (isset($_SESSION['cart'])) {
                unset($_SESSION['cart']);
            }
            if (isset($_SESSION['orderdetails'])) {
                unset($_SESSION['orderdetails']);
            }

            header("Location: $redirectUrl");
            exit;
        }

        http_response_code(200);
        echo "OK";
        exit;
    }
}

function validatePaymenthoodWebhookToken(string $webhookToken): bool
{
    if (!is_string($webhookToken) || $webhookToken === '') {
        return false; // Token not configured
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (strpos($authHeader, 'Bearer ') !== 0) {
        return false; // Missing Bearer token
    }

    $incomingToken = substr($authHeader, 7); // Remove "Bearer " prefix

    // Compare securely
    return hash_equals($webhookToken, $incomingToken);
}

function paymenthood_upsertInvoicePaymenthoodCustomFields(int $invoiceId, string $appId, string $paymentId, string $providerName): void
{
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $requiredFields = [
            'paymenthood_provider' => 'PaymentHood Payment Provider',
            'paymenthood_app_id' => 'PaymentHood App ID',
            'paymenthood_payment_id' => 'PaymentHood Payment ID',
        ];

        $customFields = Capsule::table('tblcustomfields')
            ->where('type', 'invoice')
            ->whereIn('fieldname', array_keys($requiredFields))
            ->pluck('id', 'fieldname');

        $missing = [];
        foreach ($requiredFields as $fieldName => $description) {
            if (!isset($customFields[$fieldName])) {
                $missing[$fieldName] = $description;
            }
        }

        if (!empty($missing)) {
            $now = date('Y-m-d H:i:s');
            foreach ($missing as $fieldName => $description) {
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
                    'description' => $description,
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
            }

            $customFields = Capsule::table('tblcustomfields')
                ->where('type', 'invoice')
                ->whereIn('fieldname', array_keys($requiredFields))
                ->pluck('id', 'fieldname');
        }

        $toWrite = [];
        if ($providerName !== '') {
            $toWrite['paymenthood_provider'] = $providerName;
        }
        if ($appId !== '') {
            $toWrite['paymenthood_app_id'] = $appId;
        }
        if ($paymentId !== '') {
            $toWrite['paymenthood_payment_id'] = $paymentId;
        }

        foreach ($toWrite as $fieldName => $value) {
            if (!isset($customFields[$fieldName])) {
                continue;
            }
            $fieldId = (int) $customFields[$fieldName];
            if ($fieldId <= 0) {
                continue;
            }

            $existingValue = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $invoiceId)
                ->first();

            if ($existingValue) {
                Capsule::table('tblcustomfieldsvalues')
                    ->where('id', $existingValue->id)
                    ->update(['value' => $value]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid' => $fieldId,
                    'relid' => $invoiceId,
                    'value' => $value,
                ]);
            }
        }
    } catch (Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('callback_custom_fields_store_error', [
            'invoiceId' => $invoiceId,
        ], [
            'error' => $e->getMessage(),
        ]);
    }
}