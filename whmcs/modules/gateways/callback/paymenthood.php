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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log raw input first
    $rawInput = file_get_contents('php://input');
    PaymentHoodHandler::safeLogModuleCall('callback_webhook_post_received', [], [
        'raw_input_length' => strlen($rawInput)
    ]);

    try {
        $json = json_decode($rawInput, true);

        // Log JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            PaymentHoodHandler::safeLogModuleCall('callback_webhook_json_parse_error', [], [
                'error' => json_last_error_msg(),
                'raw_input' => substr($rawInput, 0, 500)
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        PaymentHoodHandler::safeLogModuleCall('callback_webhook_post_parsed', [
            'referenceId' => $json['payment']['referenceId'] ?? null,
            'paymentState' => $json['payment']['paymentState'] ?? null
        ], []);

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

        PaymentHoodHandler::safeLogModuleCall('callback_webhook_post_completed', [
            'referenceId' => $referenceId
        ], []);
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
    PaymentHoodHandler::safeLogModuleCall('callback_webhook_get_received', [
        'referenceId' => $referenceId
    ], []);
    if (!$referenceId) {
        PaymentHoodHandler::safeLogModuleCall('callback_webhook_get_missing_reference', [], [
            'error' => 'Missing invoiceId parameter'
        ]);
        die('Missing invoiceId');
    }

    // processPaymenthoodCallback handles the redirect internally, so no need for additional redirect here
    processPaymenthoodCallback($referenceId, false);
    // If we reach here, something went wrong - processPaymenthoodCallback should have exited
    PaymentHoodHandler::safeLogModuleCall('callback_webhook_get_unexpected_fallthrough', [
        'referenceId' => $referenceId
    ], []);
    exit;
}

function processPaymenthoodCallback(string $referenceId, bool $validateAuthorization)
{
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];
    $token = $credentials['token'];
    $webhookToken = $credentials['webhookToken'];

    if (!$appId || !$token || !$webhookToken) {
        PaymentHoodHandler::safeLogModuleCall('callback_missing_configuration', [], [
            'appId' => $appId ? 'configured' : 'missing',
            'token' => $token ? 'configured' : 'missing',
            'webhookToken' => $webhookToken ? 'configured' : 'missing',
            'error' => 'Gateway not fully configured'
        ]);
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

    PaymentHoodHandler::safeLogModuleCall('callback_payment_status_check', [
        'invoiceId' => $invoiceId,
        'url' => $url
    ], [
        'httpCode' => $httpCode,
        'error' => $error ?: null
    ]);

    if (!$response || $httpCode >= 400) {
        die('Error communicating with payment gateway');
    }

    $data = json_decode($response, true);
    $paymentState = $data['paymentState'] ?? 'unknown';
    $transactionId = $data['paymentId'] ?? 'N/A'; // fallback

    // Get existing invoice data using WHMCS API
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $existing = $invoiceData['notes'] ?? '';

    if (strpos($existing, 'Payment provider state') === false) {
        try {
            $newNotes = $existing . "\nPayment provider state: $paymentState";
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'notes' => $newNotes
            ];
            $results = localAPI($command, $postData);

            PaymentHoodHandler::safeLogModuleCall('callback_invoice_notes_updated', [
                'invoiceId' => $invoiceId,
                'paymentState' => $paymentState
            ], [
                'success' => ($results['result'] ?? '') === 'success'
            ]);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_invoice_notes_update_error', [
                'invoiceId' => $invoiceId
            ], [
                'error' => $e->getMessage()
            ]);
        }
    }

    // decide for invoice based on payment provider state
    if ($paymentState === 'Captured') {
        // Payment Success
        try {
            // check invoise status
            $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
            if ($invoiceData['status'] === 'Paid') {
                PaymentHoodHandler::safeLogModuleCall('callback_payment_already_recorded', [
                    'invoiceId' => $invoiceId,
                    'transactionId' => $transactionId
                ], []);
            } else
                addInvoicePayment($invoiceId, $transactionId, $data['amount'], 0, PAYMENTHOOD_GATEWAY);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_payment_record_error', [
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'amount' => $data['amount']
            ], [
                'error' => $e->getMessage()
            ]);
        }

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentsuccess=true";
            PaymentHoodHandler::safeLogModuleCall('callback_redirect_success', [
                'invoiceId' => $invoiceId,
                'redirectUrl' => $redirectUrl
            ], []);
            
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
        // Payment Refunded - Use WHMCS API to update invoice
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Refunded',
                'notes' => 'Payment refunded via PaymentHood'
            ];
            $results = localAPI($command, $postData);

            PaymentHoodHandler::safeLogModuleCall('callback_invoice_refunded', [
                'invoiceId' => $invoiceId
            ], [
                'success' => ($results['result'] ?? '') === 'success'
            ]);
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
        // Payment Failed - Use WHMCS API to update invoice
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Cancelled',
                'notes' => 'Payment failed via PaymentHood'
            ];
            $results = localAPI($command, $postData);

            PaymentHoodHandler::safeLogModuleCall('callback_invoice_cancelled', [
                'invoiceId' => $invoiceId
            ], [
                'success' => ($results['result'] ?? '') === 'success'
            ]);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback_invoice_cancel_error', [
                'invoiceId' => $invoiceId
            ], [
                'error' => $e->getMessage()
            ]);
        }

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentfailed=true";
            PaymentHoodHandler::safeLogModuleCall('callback_redirect_failed', [
                'invoiceId' => $invoiceId,
                'redirectUrl' => $redirectUrl
            ], []);
            
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
        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $redirectUrl = PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentpending=true";
            PaymentHoodHandler::safeLogModuleCall('callback_redirect_pending', [
                'invoiceId' => $invoiceId,
                'paymentState' => $paymentState,
                'redirectUrl' => $redirectUrl
            ], []);
            
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