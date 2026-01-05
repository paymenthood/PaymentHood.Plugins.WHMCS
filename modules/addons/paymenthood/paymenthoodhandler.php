<?php
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (defined('WHMCS_MAIL') && WHMCS_MAIL) {
    // Skip API call — email generation context
    return '<a href="#">Pay with paymentHood</a>';
}

class PaymentHoodHandler
{
    const PAYMENTHOOD_GATEWAY = 'paymenthood';
    
    private static function normalizeYesNoToBool($rawValue): bool
    {
        if (is_string($rawValue)) {
            $normalized = strtolower(trim($rawValue));
            if (in_array($normalized, ['on', '1', 'yes', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['', '0', 'no', 'false'], true)) {
                return false;
            }
            // Any other non-empty string (including corrupted long strings) => treat as enabled
            return $normalized !== '';
        }
        
        return !empty($rawValue);
    }

    /**
     * Best-effort check for whether WHMCS considers this gateway module activated.
     *
     * Important: WHMCS's getGatewayVariables() will hard-stop execution (die/exit)
     * when the gateway is not activated. So we must not call getGatewayVariables()
     * unless this returns true.
     */
    private static function isWhmcsGatewayActivated(): bool
    {
        try {
            $type = Capsule::table('tblpaymentgateways')
                ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                ->whereRaw('LOWER(setting) = ?', ['type'])
                ->value('value');

            $type = is_string($type) ? trim($type) : $type;
            return $type !== null && $type !== '';
        } catch (\Throwable $e) {
            // If DB is unavailable for some reason, treat as not activated to stay safe.
            return false;
        }
    }
    
    /**
     * Returns true when sandbox mode is enabled (IsSandboxActivated).
     * - Prefers WHMCS gateway variables when available
     * - Falls back to tblpaymentgateways when gateway variables are unavailable
     * - Attempts decrypt on long non-standard values if decrypt() is available
     *
     * This method is read-only (does not write to the DB).
     */
    public static function isSandboxModeEnabled(): bool
    {
        // Ensure WHMCS gateway helpers are available in all contexts (addon, hooks, cron, etc.)
        if (!function_exists('getGatewayVariables') && defined('ROOTDIR')) {
            $gatewayFunctionsPath = ROOTDIR . '/includes/gatewayfunctions.php';
            if (is_file($gatewayFunctionsPath)) {
                require_once $gatewayFunctionsPath;
            }
        }
        if (!function_exists('decrypt') && defined('ROOTDIR')) {
            $functionsPath = ROOTDIR . '/includes/functions.php';
            if (is_file($functionsPath)) {
                require_once $functionsPath;
            }
        }
        
        $rawSandboxValue = null;

        // Only call getGatewayVariables() when the gateway is activated.
        // Otherwise WHMCS will die with: Gateway Module "paymenthood" Not Activated
        if (function_exists('getGatewayVariables') && self::isWhmcsGatewayActivated()) {
            $gatewayVars = getGatewayVariables(self::PAYMENTHOOD_GATEWAY);
            $rawSandboxValue = $gatewayVars['IsSandboxActivated'] ?? null;
        }
        
        if ($rawSandboxValue === null) {
            $rawSandboxValue = Capsule::table('tblpaymentgateways')
                ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                ->whereRaw('LOWER(setting) = ?', ['issandboxactivated'])
                ->value('value');
        }
        
        if ($rawSandboxValue === null) {
            $rawSandboxValue = '';
        }
        
        // If a long non-standard value is stored, try to decrypt it.
        if (is_string($rawSandboxValue)) {
            $trimmed = trim($rawSandboxValue);
            $normalized = strtolower($trimmed);
            $isStandard = in_array($normalized, ['on', '1', 'yes', 'true', '', '0', 'no', 'false'], true);
            
            if (!$isStandard && $trimmed !== '' && strlen($trimmed) > 10 && function_exists('decrypt')) {
                try {
                    $rawSandboxValue = (string) decrypt($trimmed);
                } catch (\Throwable $e) {
                    // Ignore decrypt failure; fall back to best-effort normalization
                }
            } else {
                $rawSandboxValue = $trimmed;
            }
        }
        
        return self::normalizeYesNoToBool($rawSandboxValue);
    }

    /**
     * Safe wrapper for logging that works across WHMCS contexts
     * @param string $action Action being performed
     * @param array|string $request Request data or simple string data
     * @param array $response Response data
     * @param string $trace Optional trace information
     */
    public static function safeLogModuleCall($action, $request = [], $response = [], $trace = null)
    {
        // Convert string request to array for consistency
        if (is_string($request)) {
            $request = ['data' => $request];
        }

        // In some WHMCS contexts (like addons), logModuleCall might not be available
        if (function_exists('logModuleCall')) {
            if ($trace !== null) {
                return logModuleCall(self::PAYMENTHOOD_GATEWAY, $action, $request, $response, $trace);
            }
            return logModuleCall(self::PAYMENTHOOD_GATEWAY, $action, $request, $response);
        }

        // Fallback logging for contexts where logModuleCall isn't available
        $logData = [
            'module' => self::PAYMENTHOOD_GATEWAY,
            'action' => $action,
            'request' => $request,
            'response' => $response,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        if ($trace !== null) {
            $logData['trace'] = $trace;
        }
        error_log('PaymentHood Module: ' . json_encode($logData));
    }

    private static function appendInvoiceNoteIfMissing(int $invoiceId, string $marker, string $noteLine): void
    {
        if ($invoiceId <= 0 || $marker === '' || $noteLine === '') {
            return;
        }

        try {
            $existing = Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->value('notes');

            $existing = is_string($existing) ? $existing : '';
            if ($existing !== '' && stripos($existing, $marker) !== false) {
                return;
            }

            $newNotes = trim($existing);
            $newNotes = $newNotes !== '' ? ($newNotes . "\n" . $noteLine) : $noteLine;

            Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update(['notes' => $newNotes]);
        } catch (\Throwable $e) {
            self::safeLogModuleCall('appendInvoiceNoteIfMissing_error', [
                'invoiceId' => $invoiceId,
            ], [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function handleInvoice(array $params)
    {
        try {
            $clientId = (int) ($params['clientdetails']['userid'] ?? 0);
            $invoiceId = (int) ($params['invoiceid'] ?? 0);

            $isInvoicePayment = $invoiceId > 0;
            $cartProducts = $_SESSION['cart']['products'] ?? [];
            $isCartCheckout = !$isInvoicePayment && !empty($cartProducts);

            $credentials = self::getGatewayCredentials();
            $appId = $credentials['appId'];
            $token = $credentials['token'];
            $useSandbox = !empty($credentials['useSandbox']);

            $orderId = null;

            // If this is an invoice payment, ensure a WHMCS order exists linked to the invoice
            if ($isInvoicePayment) {
                $orderId = Capsule::table('tblorders')
                    ->where('invoiceid', $invoiceId)
                    ->value('id');

                if (!$orderId) {
                    // Check if there's an orphan order (order with no invoice) for this user
                    // This can happen when WHMCS creates an order during checkout before invoice assignment
                    // Try multiple search strategies to find the orphan order

                    // Strategy 1: Match by payment method and status
                    $orphanOrder = Capsule::table('tblorders')
                        ->where('userid', $clientId)
                        ->where('paymentmethod', self::PAYMENTHOOD_GATEWAY)
                        ->whereIn('status', ['Pending', 'Active'])
                        ->where(function ($query) use ($invoiceId) {
                            $query->whereNull('invoiceid')
                                ->orWhere('invoiceid', 0);
                        })
                        ->orderBy('id', 'desc')
                        ->first();

                    // Strategy 2: If not found, look for recent order without payment method set
                    if (!$orphanOrder) {
                        $orphanOrder = Capsule::table('tblorders')
                            ->where('userid', $clientId)
                            ->whereIn('status', ['Pending', 'Active'])
                            ->where(function ($query) use ($invoiceId) {
                                $query->whereNull('invoiceid')
                                    ->orWhere('invoiceid', 0);
                            })
                            ->where('date', '>=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
                            ->orderBy('id', 'desc')
                            ->first();
                    }

                    self::safeLogModuleCall('handler_invoice_order_search', [
                        'invoiceId' => $invoiceId,
                        'clientId' => $clientId
                    ], [
                        'orphanOrderFound' => $orphanOrder ? true : false,
                        'orphanOrderId' => $orphanOrder ? $orphanOrder->id : null,
                        'orphanOrderPaymentMethod' => $orphanOrder ? $orphanOrder->paymentmethod : null
                    ]);

                    if ($orphanOrder) {
                        // Link the existing orphan order to this invoice
                        Capsule::table('tblorders')
                            ->where('id', $orphanOrder->id)
                            ->update([
                                'invoiceid' => $invoiceId,
                                'paymentmethod' => self::PAYMENTHOOD_GATEWAY, // Ensure payment method is set
                                'notes' => ($orphanOrder->notes ? $orphanOrder->notes . "\n" : '') . 'Linked to invoice #' . $invoiceId
                            ]);

                        $orderId = $orphanOrder->id;

                        self::safeLogModuleCall('handler_invoice_order_linked', [
                            'invoiceId' => $invoiceId,
                            'orderId' => $orderId,
                            'orphanOrderId' => $orphanOrder->id
                        ], []);
                    } else {
                        // Generate order number using WHMCS function
                        $orderNumber = Capsule::table('tblorders')->max('ordernum') + 1;

                        // Create a minimal order linked to invoice using direct DB insert
                        $orderId = Capsule::table('tblorders')->insertGetId([
                            'userid' => $clientId,
                            'ordernum' => $orderNumber,
                            'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                            'status' => 'Pending',
                            'amount' => $params['amount'] ?? 0,
                            'invoiceid' => $invoiceId,
                            'date' => date('Y-m-d H:i:s'),
                            'notes' => 'Linked to existing invoice #' . $invoiceId,
                        ]);

                        self::safeLogModuleCall('handler_invoice_order_created', [
                            'invoiceId' => $invoiceId,
                            'orderId' => $orderId,
                            'orderNumber' => $orderNumber
                        ], []);
                    }
                }
            }

            // Cart checkout: create order via AddOrder only if there are products
            if ($isCartCheckout) {
                $apiParams = [
                    'clientid' => $clientId,
                    'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                    'status' => 'Pending',
                    'sendemail' => false,
                    'notes' => 'Created by PaymentHood gateway',
                    'pid' => array_column($cartProducts, 'pid'),
                    'qty' => array_column($cartProducts, 'qty'),
                    'billingcycle' => array_column($cartProducts, 'billingcycle'),
                ];

                $apiResult = localAPI('AddOrder', $apiParams);
                self::safeLogModuleCall('handler_cart_order_created', [
                    'clientId' => $clientId,
                    'productCount' => count($cartProducts)
                ], [
                    'success' => ($apiResult['result'] ?? '') === 'success',
                    'orderId' => $apiResult['orderid'] ?? null
                ]);

                if (!isset($apiResult['result']) || $apiResult['result'] !== 'success') {
                    return '<div class="alert alert-danger">Failed to create order. Please check your cart and try again.</div>';
                }
            }

            // Check invoice age to detect checkout flow
            $invoiceAge = 0;
            if ($invoiceId) {
                $invoiceDate = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('date');
                if ($invoiceDate) {
                    $invoiceAge = time() - strtotime($invoiceDate);
                }
            }

            // Decide if we should redirect to PaymentHood
            // Redirect when:
            // 1. User explicitly submitted payment form (POST with paymentmethod)
            // 2. Invoice just created (< 60 seconds) - coming from checkout flow
            $shouldRedirect = (
                ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['paymentmethod'] ?? '') === self::PAYMENTHOOD_GATEWAY) ||
                ($isInvoicePayment && $invoiceAge > 0 && $invoiceAge < 60)
            );

            self::safeLogModuleCall('handler_redirect_decision', [
                'invoiceId' => $invoiceId,
                'invoiceAge' => $invoiceAge,
                'isInvoicePayment' => $isInvoicePayment,
                'requestMethod' => $_SERVER['REQUEST_METHOD']
            ], [
                'shouldRedirect' => $shouldRedirect
            ]);

            if ($shouldRedirect) {
                $amount = $params['amount'];
                $currency = $params['currency'];
                $clientEmail = $params['clientdetails']['email'] ?? '';

                // Check if invoice contains any recurring/subscription items
                $hasRecurringItem = false;
                if ($invoiceId) {
                    $hasRecurringItem = Capsule::table('tblinvoiceitems as ii')
                        ->join('tblhosting as h', 'ii.relid', '=', 'h.id')
                        ->where('ii.invoiceid', $invoiceId)
                        ->where('ii.type', 'Hosting')
                        ->whereNotIn('h.billingcycle', ['One Time', 'Free', ''])
                        ->exists();
                }

                self::safeLogModuleCall('handler_check_recurring_items', [
                    'invoiceId' => $invoiceId
                ], [
                    'hasRecurringItem' => $hasRecurringItem
                ]);

                $callbackUrl = rtrim(self::getSystemUrl(), '/') . '/modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;
                $postData = [
                    'referenceId' => (string) $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'autoCapture' => true,
                    'webhookUrl' => $callbackUrl,
                    'customerOrder' => [
                        'customer' => [
                            'customerId' => (string) $clientId,
                            'email' => $clientEmail,
                        ],
                    ],
                    'returnUrl' => $callbackUrl,
                    'showPayRecurringInCheckout' => $hasRecurringItem,
                ];
                self::safeLogModuleCall('handler_redirect_decision foooooooo', [
                    'postData' => $postData
                ], []);

                try {
                    $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/hosted-page", $postData, $token);
                } catch (\Throwable $apiEx) {
                    $msg = (string) $apiEx->getMessage();
                    $needle = 'Can not find any profile for the app for specific currency';

                    if ($msg !== '' && stripos($msg, $needle) !== false) {
                        $manageUrl = rtrim(self::paymenthood_ConsoleUrl(), '/') . '/' . urlencode((string) $appId) . '/gateways';
                        $sandboxNotice = '';
                        if (!empty($useSandbox)) {
                            $sandboxNotice = '<div id="paymenthood-sandbox-notice" class="alert alert-info" role="alert" style="margin-bottom:10px;"><strong>Sandbox Mode</strong> is enabled for PaymentHood. Payments will use sandbox credentials.</div>';
                        }
                        $html = '<div class="alert alert-warning" role="alert">'
                            . '<strong>PaymentHood is not configured for this currency.</strong><br />'
                            . 'You have not defined any payment gateway/profile for this app and currency yet.<br />'
                            . 'Please configure your gateways in the PaymentHood Console: '
                            . '<a href="' . htmlspecialchars($manageUrl) . '" target="_blank" rel="noopener noreferrer">Manage PaymentHood Gateways</a>.'
                            . '</div>';
                        return $sandboxNotice . $html;
                    }

                    throw $apiEx;
                }
                self::safeLogModuleCall('handler_payment_created', [
                    'invoiceId' => $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency
                ], [
                    'redirectUrl' => $response['redirectUrl'] ?? null,
                    'paymentId' => $response['paymentId'] ?? null
                ]);

                if (empty($response['redirectUrl'])) {
                    return '<p>Payment gateway returned an invalid response.</p>';
                }

                // Record sandbox usage in invoice notes (do not overwrite existing notes).
                if (!empty($useSandbox) && !empty($invoiceId)) {
                    $currencySafe = is_string($currency) ? trim($currency) : '';
                    $noteLine = '[PaymentHood Sandbox] Sandbox Mode enabled for this payment.'
                        . ($currencySafe !== '' ? (' Currency: ' . $currencySafe . '.') : '');
                    self::appendInvoiceNoteIfMissing((int) $invoiceId, '[PaymentHood Sandbox]', $noteLine);
                }

                // Redirect immediately to PaymentHood
                header('Location: ' . $response['redirectUrl']);
                exit;
            }

            // Initial render: show Pay button UI
            // If we reach here, it's NOT a checkout flow (those redirect above)
            self::safeLogModuleCall('handler_render_ui', [
                'invoiceId' => $invoiceId,
                'isInvoicePayment' => $isInvoicePayment
            ], []);

            return self::renderInvoiceUI((string) $invoiceId, $appId, $token, false, $useSandbox);

        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_invoice_exception', [
                'invoiceId' => $params['invoiceid'] ?? null,
                'clientId' => $params['clientdetails']['userid'] ?? null
            ], [
                'error' => $ex->getMessage(),
                'trace' => $ex->getTraceAsString()
            ]);
            $isSandbox = self::isSandboxModeEnabled();
            $sandboxNotice = '';
            if ($isSandbox) {
                $sandboxNotice = '<div id="paymenthood-sandbox-notice" class="alert alert-info" role="alert" style="margin-bottom:10px;"><strong>Sandbox Mode</strong> is enabled for PaymentHood. Payments will use sandbox credentials.</div>';
            }
            return $sandboxNotice . '<div class="alert alert-danger">paymentHood Error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
        }
    }

    public static function renderInvoiceUI($invoiceId, $appId, $token, $autoSubmit = false, $useSandbox = false)
    {
        // Check if payment already exists
        $paymentStatus = self::checkInvoiceStatus($invoiceId, $appId, $token);

        $sandboxNotice = '';
        if ($useSandbox) {
            $sandboxNotice = '<div id="paymenthood-sandbox-notice" class="alert alert-info" role="alert" style="margin-bottom:10px;"><strong>Sandbox Mode</strong> is enabled for PaymentHood. Payments will use sandbox credentials.</div>';
        }

        self::safeLogModuleCall('handler_render_invoice_ui', [
            'invoiceId' => $invoiceId,
            'autoSubmit' => $autoSubmit
        ], [
            'paymentExists' => $paymentStatus['exists'] ?? false,
            'redirectUrl' => $paymentStatus['redirectUrl'] ?? null
        ]);

        if ($paymentStatus && isset($paymentStatus['exists']) && $paymentStatus['exists'] === true) {
            // Payment exists - redirect immediately if auto-submit, otherwise show button
            $redirectUrl = $paymentStatus['redirectUrl'] ?? '';

            if ($redirectUrl) {
                if ($autoSubmit) {
                    self::safeLogModuleCall('handler_payment_auto_redirect', [
                        'invoiceId' => $invoiceId,
                        'redirectUrl' => $redirectUrl
                    ], []);
                    header('Location: ' . $redirectUrl);
                    exit;
                }

                self::safeLogModuleCall('handler_payment_continue_button', [
                    'invoiceId' => $invoiceId,
                    'redirectUrl' => $redirectUrl
                ], []);
                $html = $sandboxNotice;
                $html .= '<div class="alert alert-info">A payment session is already in progress for this invoice.</div>';
                $html .= '<a href="' . htmlspecialchars($redirectUrl) . '" class="btn btn-primary btn-block">Continue to Payment</a>';
                return $html . self::loadHidePaymentMethodsJS();
            }

            // Fallback: show a warning if redirect URL is missing
            self::safeLogModuleCall('handler_payment_missing_redirect', [
                'invoiceId' => $invoiceId
            ], [
                'error' => 'Payment exists but redirectUrl missing',
                'paymentId' => $paymentStatus['paymentId'] ?? null
            ]);
            $html = $sandboxNotice;
            $html .= '<div class="alert alert-warning">This invoice cannot be paid via PaymentHood at the moment.</div>';
            return $html . self::loadHidePaymentMethodsJS();
        } else {
            // No payment found, show the payment button
            $systemUrl = self::getSystemUrl();
            $formAction = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

            $html = $sandboxNotice;
            $html .= '<form id="paymenthood-form" method="post" action="' . htmlspecialchars($formAction) . '">
                        <input type="hidden" name="invoiceid" value="' . htmlspecialchars($invoiceId) . '" />
                        <input type="hidden" name="paymentmethod" value="' . self::PAYMENTHOOD_GATEWAY . '" />
                        <button type="submit" class="btn btn-success btn-block">Pay Now with PaymentHood</button>
                    </form>';

            // Add auto-submit JavaScript only if requested (checkout flow)
            $autoSubmitJs = '';
            if ($autoSubmit) {
                $autoSubmitJs = '<script>(function(){var f=document.getElementById("paymenthood-form");if(f){f.submit();}})();</script>';
                self::safeLogModuleCall('handler_pay_button_auto_submit', [
                    'invoiceId' => $invoiceId
                ], []);
            } else {
                self::safeLogModuleCall('handler_pay_button_rendered', [
                    'invoiceId' => $invoiceId
                ], []);
            }

            return $html . $autoSubmitJs . self::loadHidePaymentMethodsJS();
        }
    }

    public static function loadHidePaymentMethodsJS()
    {
        return <<<HTML
<script>
(function() {
    function hidePaymentMethodSelector() {
        // Hide all radio buttons and their containers
        var radios = document.querySelectorAll("input[type='radio'][name='paymentmethod']");
        radios.forEach(function(radio) {
            var container = radio.closest('.form-group') || radio.closest('.panel') || radio.closest('div.payment-methods');
            if (container) {
                container.style.display = 'none';
            } else {
                var parent = radio.closest('label') || radio.parentElement;
                if (parent) parent.style.display = 'none';
            }
        });

        // Hide dropdown and its container
        var dropdown = document.querySelector("select[name='paymentmethod']");
        if (dropdown) {
            var container = dropdown.closest('.form-group') || dropdown.closest('.panel') || dropdown.parentElement;
            if (container) {
                container.style.display = 'none';
            } else {
                dropdown.style.display = 'none';
            }
        }

        // Hide any labels for payment method selection
        var labels = document.querySelectorAll('label[for*="paymentmethod"], label');
        labels.forEach(function(label) {
            if (label.textContent.match(/payment\s*method/i)) {
                label.style.display = 'none';
            }
        });
    }

    // Run initially
    hidePaymentMethodSelector();

    // Observe the entire body for dynamically added content
    if (window.MutationObserver) {
        var observer = new MutationObserver(function() {
            hidePaymentMethodSelector();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
</script>
HTML;
    }

    public static function processUnpaidInvoices()
    {
        self::safeLogModuleCall('handler_cron_unpaid_invoices_start', [], []);

        // Check if PaymentHood gateway is activated
        $activated = Capsule::table('tblpaymentgateways')
            ->where('gateway', self::PAYMENTHOOD_GATEWAY)
            ->where('setting', 'activated')
            ->value('value');

        if ($activated != '1') {
            self::safeLogModuleCall('handler_cron_gateway_not_activated', [], [
                'activated' => $activated,
                'message' => 'PaymentHood gateway is not activated, skipping auto-payment processing'
            ]);
            return;
        }

        try {
            // Get RENEWAL invoices ONLY for recurring/subscription products that use paymentHood
            // Initial purchases are paid manually via hosted payment page
            // Auto-payment is only for renewals (when duedate = nextduedate)
            $invoices = Capsule::table('tblinvoices as i')
                ->join('tblinvoiceitems as ii', 'ii.invoiceid', '=', 'i.id')
                ->join('tblhosting as h', 'ii.relid', '=', 'h.id')
                ->where('i.status', 'Unpaid')
                ->where('i.paymentmethod', self::PAYMENTHOOD_GATEWAY)
                ->where('ii.type', 'Hosting')
                ->whereNotIn('h.billingcycle', ['One Time', 'Free', ''])
                ->whereColumn('i.duedate', '=', 'h.nextduedate') // ONLY renewal invoices
                ->select('i.id as invoiceId', 'i.userid', 'i.total', 'i.duedate', 'h.billingcycle', 'h.nextduedate')
                ->distinct()
                ->get();

            foreach ($invoices as $invoice) {
                $invoiceId = $invoice->invoiceId;
                $clientId = $invoice->userid;

                self::safeLogModuleCall(
                    'processUnpaidInvoices',
                    [
                        'invoiceId' => $invoiceId,
                        'clientId' => $clientId,
                        'billingCycle' => $invoice->billingcycle,
                        'dueDate' => $invoice->duedate,
                        'nextDueDate' => $invoice->nextduedate
                    ]
                );

                try {
                    // Call paymentHood Auto-Payment API
                    self::safeLogModuleCall(
                        'processUnpaidInvoices-pre createAutoPayment',
                        ["ClientID: $clientId, InvoiceID: $invoiceId, Amount: {$invoice->total}"],
                        []
                    );
                    $result = self::createAutoPayment($clientId, $invoiceId, $invoice->total);
                    self::safeLogModuleCall('processUnpaidInvoices-createAutoPayment', 'result', $result);

                    // Handle result based on status
                    if ($result['status'] === 'success') {
                        // Payment created successfully, continue to next invoice
                        continue;
                    } elseif ($result['status'] === 'paid') {
                        // Payment already captured, mark invoice as paid
                        try {
                            $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
                            if ($invoiceData['status'] === 'Paid') {
                                PaymentHoodHandler::safeLogModuleCall('processUnpaidInvoices - already paid', [
                                    'invoiceId' => $invoiceId,
                                    'transactionId' => $result['paymentId']
                                ]);
                            } else {
                                $command = 'UpdateInvoice';
                                $postData = [
                                    'invoiceid' => $invoiceId,
                                    'status' => 'Paid',
                                ];
                                $results = localAPI($command, $postData);

                                if ($results['result'] === 'success') {
                                    self::safeLogModuleCall('Invoice marked as paid', ['invoiceId' => $invoiceId], $results);
                                } else {
                                    self::safeLogModuleCall('Failed to mark invoice as paid', ['invoiceId' => $invoiceId], $results);
                                }
                            }
                        } catch (\Exception $apiEx) {
                            self::safeLogModuleCall('Error updating invoice to paid', ['invoiceId' => $invoiceId], ['error' => $apiEx->getMessage()]);
                        }
                    }

                } catch (\Exception $ex) {
                    throw new \Exception(
                        "Error processing auto-payment for Invoice #{$invoiceId}: " . $ex->getMessage(),
                        $ex->getCode(),
                        previous: $ex
                    );
                }
            }
        } catch (\Exception $ex) {
            self::safeLogModuleCall(
                'processUnpaidInvoices - Error',
                [],
                ['error' => $ex->getMessage()]
            );
        }
    }

    private static function createAutoPayment(string $clientId, string $invoiceId, string $amount)
    {
        try {
            $credentials = self::getGatewayCredentials();
            $appId = $credentials['appId'];
            $token = $credentials['token'];

            self::safeLogModuleCall('createAutoPayment', ['appId' => $appId]);
            $callbackUrl = rtrim(self::getSystemUrl(), '/') . '/modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;

            $postData = [
                "referenceId" => $invoiceId,
                "amount" => $amount,
                "autoCapture" => true,
                "webhookUrl" => $callbackUrl,
                "customerOrder" => [
                    "customer" => [
                        "customerId" => $clientId,
                    ]
                ]
            ];

            $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/auto-payment", $postData, $token);
            self::safeLogModuleCall('createAutoPayment - result', $response);

            $httpCode = $response['_httpCode'] ?? null;

            // handle duplicate reference
            if (isset($response['Message']) && strpos($response['Message'], 'ProviderReferenceId already used') !== false) {
                self::safeLogModuleCall('Duplicate Payment - checking existing payment state', $response);

                // Get existing payment by reference
                $url = self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/referenceId:$invoiceId";
                $existingPayment = self::callApi($url, [], $token, 'GET');
                self::safeLogModuleCall('createAutoPayment - existing payment', $existingPayment);

                $paymentState = $existingPayment['paymentState'] ?? null;

                if ($paymentState === 'Captured') {
                    return [
                        'status' => 'paid',
                        'paymentId' => $existingPayment['paymentId'] ?? $existingPayment['id'] ?? null
                    ];
                } else {
                    return [
                        'status' => 'success',
                        'paymentId' => $existingPayment['paymentId'] ?? $existingPayment['id'] ?? null
                    ];
                }
            }

            // HTTP 200 means payment created successfully
            if ($httpCode === 200) {
                return [
                    'status' => 'success',
                    'paymentId' => $response['paymentId'] ?? $response['id'] ?? null
                ];
            }

            return [
                'status' => 'success',
                'paymentId' => $response['paymentId'] ?? $response['id'] ?? null
            ];
        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_exception', ['invoiceId' => $invoiceId], ['error' => $ex->getMessage()], $ex->getTraceAsString());
            return [
                'status' => 'error',
                'rawdata' => $ex->getMessage(),
            ];
        }
    }

    private static function callApi(string $url, array $data, string $token, string $method = 'POST'): array
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $token",
                "Content-Type: application/json"
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 404) {
                self::safeLogModuleCall('API not found (404)', ['url' => $url], ['httpCode' => $httpCode, 'response' => $response]);
                return [
                    '_httpCode' => 404,
                    '_rawResponse' => $response,
                ];
            }

            if (!$response || $httpCode >= 400) {
                self::safeLogModuleCall('handler_api_error', [
                    'url' => $url
                ], [
                    'httpCode' => $httpCode,
                    'response' => substr($response, 0, 500)
                ]);
                throw new \Exception($response);
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $decoded['_httpCode'] = $httpCode;
            return $decoded;
        } catch (\Exception $ex) {
            self::safeLogModuleCall('app call error', isset($httpCode) ? $httpCode : null, [], $ex->getMessage());
            throw $ex;
        }
    }

    private static function cancelInvoice(string $invoiceId)
    {
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Cancelled',
            ];
            $results = localAPI($command, $postData);

            if ($results['result'] !== 'success') {
                throw new \Exception('Failed to cancel invoice: ' . ($results['message'] ?? 'Unknown error'));
            }

            self::safeLogModuleCall('invoice cancelled', ['invoiceId' => $invoiceId]);
        } catch (\Exception $ex) {
            self::safeLogModuleCall('invoice cancel error', ['invoiceId' => $invoiceId], ['error' => $ex->getMessage()]);
            throw $ex;
        }
    }

    public static function checkInvoiceStatus(string $invoiceId, string $appId, string $token)
    {
        $status = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        self::safeLogModuleCall('handler_check_invoice_status', [
            'invoiceId' => $invoiceId
        ], [
            'status' => $status
        ]);

        // If invoice is not unpaid, nothing to do.
        if ($status !== 'Unpaid') {
            return null;
        }

        $url = self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/referenceId:$invoiceId";

        try {
            $response = self::callApi($url, [], $token, 'GET');

            $httpCode = $response['_httpCode'] ?? null;

            // If payment API returns 404, we assume no existing payment.
            // Do NOT cancel invoice so client can try to pay again.
            if ($httpCode === 404) {
                self::safeLogModuleCall('checkInvoiceStatus - payment not found, allowing retry', [
                    'invoiceId' => $invoiceId,
                    'url' => $url,
                ], [
                    'httpCode' => $httpCode,
                ]);
                return ['exists' => false];
            }

            // If API returned something falsy (empty array / null) and it is not 404,
            // fall back to previous behaviour: cancel invoice.
            if (!$response) {
                self::safeLogModuleCall('checkInvoiceStatus - empty response, cancelling invoice', [
                    'invoiceId' => $invoiceId,
                    'url' => $url,
                ]);
                self::cancelInvoice($invoiceId);
                return ['exists' => false];
            }

            // Payment exists, return the payment information including redirectUrl
            return [
                'exists' => true,
                'paymentId' => $response['paymentId'] ?? $response['id'] ?? 'N/A',
                'status' => $response['paymentState'] ?? 'Unknown',
                'amount' => $response['amount'] ?? null,
                'currency' => $response['currency'] ?? null,
                'redirectUrl' => $response['redirectUrl'] ?? null,
            ];
        } catch (\Throwable $ex) {
            // On any exception, log and leave invoice untouched so customer can retry.
            self::safeLogModuleCall('checkInvoiceStatus_exception', [
                'invoiceId' => $invoiceId,
                'url' => $url,
            ], [
                'error' => $ex->getMessage(),
            ], $ex->getTraceAsString());
            return ['exists' => false];
        }
    }

    public static function getGatewaySandboxAppId()
    {
        try {
            $value = Capsule::table('tblpaymentgateways')
                ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                ->whereRaw('LOWER(setting) = ?', ['sandboxappid'])
                ->value('value');

            $value = is_string($value) ? trim($value) : $value;
            return $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            self::safeLogModuleCall('getGatewaySandboxAppId_error', [], [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function getGatewayLiveAppId()
    {
        try {
            $value = Capsule::table('tblpaymentgateways')
                ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                ->whereRaw('LOWER(setting) = ?', ['liveappid'])
                ->value('value');

            $value = is_string($value) ? trim($value) : $value;
            return $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            self::safeLogModuleCall('getGatewayLiveAppId_error', [], [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function getGatewayCredentials()
    {
        self::safeLogModuleCall('getGatewayCredentials - start', [], []);

        $useSandbox = self::isSandboxModeEnabled();

        self::safeLogModuleCall('getGatewayCredentials - mode selected', [
            'useSandbox' => $useSandbox
        ], []);

        $appIdSetting = $useSandbox ? 'SandboxAppId' : 'LiveAppId';
        $tokenSetting = $useSandbox ? 'SandboxAppToken' : 'LiveAppToken';

        // Fetch credentials as stored in tblpaymentgateways.
        $rows = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'paymenthood')
            ->whereIn('setting', [$appIdSetting, $tokenSetting, 'webhookToken'])
            ->get()
            ->keyBy('setting');

        $appId = isset($rows[$appIdSetting]) ? $rows[$appIdSetting]->value : null;
        $token = isset($rows[$tokenSetting]) ? $rows[$tokenSetting]->value : null;
        $webhookToken = isset($rows['webhookToken']) ? $rows['webhookToken']->value : null;
        return ['appId' => $appId, 'token' => $token, 'webhookToken' => $webhookToken, 'useSandbox' => $useSandbox];
    }

    public static function getSystemUrl()
    {
        $systemUrl = Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value');

        if ($systemUrl) {
            // Ensure it ends with a slash
            return rtrim($systemUrl, '/') . '/';
        }

        return null;
    }

    public static function paymenthood_ConsoleUrl(): string
    {
        return rtrim('https://console.paymenthood.com/', '/');
    }

    public static function paymenthood_getPaymentAppBaseUrl(): string
    {
        return rtrim('https://appapi.paymenthood.com/api/', '/');
    }

    public static function paymenthood_grantAuthorizationUrl(): string
    {
        return self::paymenthood_ConsoleUrl() . '/auth/signin';
    }

    public static function paymenthood_getPaymentBaseUrl(): string
    {
        return rtrim('https://api.paymenthood.com/api/v1', '/');
    }
}
