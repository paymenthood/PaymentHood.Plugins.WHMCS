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

            $orderId = null;

            // If this is an invoice payment, ensure a WHMCS order exists linked to the invoice
            if ($isInvoicePayment) {
                $orderId = Capsule::table('tblorders')
                    ->where('invoiceid', $invoiceId)
                    ->value('id');

                if (!$orderId) {
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
                ];

                $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/hosted-page", $postData, $token);
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

            return self::renderInvoiceUI((string) $invoiceId, $appId, $token, false);

        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_invoice_exception', [
                'invoiceId' => $params['invoiceid'] ?? null,
                'clientId' => $params['clientdetails']['userid'] ?? null
            ], [
                'error' => $ex->getMessage(),
                'trace' => $ex->getTraceAsString()
            ]);
            return '<div class="alert alert-danger">paymentHood Error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
        }
    }

    public static function handleInvoice2(array $params)
    {
        try {
            // Use the existing invoice context; do NOT create orders here.
            $clientId = (int) ($params['clientdetails']['userid'] ?? 0);
            $invoiceId = (int) ($params['invoiceid'] ?? 0);

            if ($invoiceId <= 0) {
                return '<div class="alert alert-danger">Invalid invoice context.</div>';
            }

            $credentials = self::getGatewayCredentials();
            $appId = $credentials['appId'];
            $token = $credentials['token'];

            // Ensure the order exists
            $orderId = Capsule::table('tblorders')
                ->where('invoiceid', $invoiceId)
                ->value('id');

            // Check if this is a fresh order (created in last 30 seconds) to force immediate redirect
            $orderIsRecent = false;
            if ($orderId) {
                $orderCreatedAt = Capsule::table('tblorders')
                    ->where('id', $orderId)
                    ->value('date');
                if ($orderCreatedAt) {
                    $orderAge = time() - strtotime($orderCreatedAt);
                    $orderIsRecent = ($orderAge < 30); // Consider orders created in last 30 seconds as "just created"
                    self::safeLogModuleCall('handleInvoice - order age check', [
                        'orderId' => $orderId,
                        'orderCreatedAt' => $orderCreatedAt,
                        'orderAge' => $orderAge,
                        'orderIsRecent' => $orderIsRecent
                    ]);
                }
            }

            $orderJustCreated = false;
            if (!$orderId) {
                self::safeLogModuleCall('handleInvoice - create order manually', ['appId' => $appId, 'invoiceId' => $invoiceId]);
                // Try to get the order via WHMCS helper function
                // Or create a manual order
                // Prefer WHMCS Admin API to create orders so hooks and defaults run
                try {
                    $apiParams = [
                        'clientid' => (int) $clientId,
                        'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                        'status' => 'Pending',
                        'sendemail' => false,
                        'notes' => 'Created by PaymentHood link handler',
                    ];
                    $apiResult = localAPI('AddOrder', $apiParams);
                    self::safeLogModuleCall('AddOrder-attempt', $apiParams, $apiResult);
                    if (isset($apiResult['result']) && $apiResult['result'] === 'success') {
                        $orderId = (int) ($apiResult['orderid'] ?? 0);
                    } else {
                        // Fallback to direct DB insert if API fails
                        $orderNumber = Capsule::table('tblorders')->max('ordernum') + 1;
                        $orderId = Capsule::table('tblorders')->insertGetId([
                            'userid' => $clientId,
                            'ordernum' => $orderNumber,
                            'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                            'status' => 'Pending',
                            'date' => date('Y-m-d H:i:s'),
                            'notes' => 'Created by PaymentHood link handler',
                        ]);
                        self::safeLogModuleCall('AddOrder-fallback-db', ['clientId' => $clientId], ['orderId' => $orderId, 'orderNumber' => $orderNumber]);
                    }
                } catch (\Throwable $apiEx) {
                    // Fallback to direct DB insert if localAPI not available in this context
                    self::safeLogModuleCall('AddOrder-exception-fallback-db', ['clientId' => $clientId], ['error' => $apiEx->getMessage()]);
                    $orderNumber = Capsule::table('tblorders')->max('ordernum') + 1;
                    $orderId = Capsule::table('tblorders')->insertGetId([
                        'userid' => $clientId,
                        'ordernum' => $orderNumber,
                        'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                        'status' => 'Pending',
                        'date' => date('Y-m-d H:i:s'),
                        'notes' => 'Created by PaymentHood link handler',
                    ]);
                }

                // Optionally, link the invoice to the order if already generated
                if ($invoiceId) {
                    // Prefer WHMCS Admin API to update order linkage; fallback to DB if not supported
                    try {
                        $apiParams = [
                            'orderid' => (int) $orderId,
                            // Some WHMCS versions may not support setting invoiceid via UpdateOrder
                            // Include common fields to avoid partial updates being rejected
                            'status' => 'Pending',
                            'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                            'notes' => 'Linked to Invoice #' . (int) $invoiceId,
                        ];
                        $apiResult = localAPI('UpdateOrder', $apiParams);
                        self::safeLogModuleCall('UpdateOrder-link-invoice-attempt', $apiParams, $apiResult);

                        // If API doesn't support setting invoice link directly, perform safe fallback
                        if (!isset($apiResult['result']) || $apiResult['result'] !== 'success') {
                            Capsule::table('tblorders')
                                ->where('id', $orderId)
                                ->update(['invoiceid' => $invoiceId]);
                            self::safeLogModuleCall('Fallback-DB-UpdateOrder-InvoiceLink', ['orderId' => $orderId, 'invoiceId' => $invoiceId]);
                        }
                    } catch (\Throwable $apiEx) {
                        // Fallback to direct DB update if localAPI fails in this context
                        Capsule::table('tblorders')
                            ->where('id', $orderId)
                            ->update(['invoiceid' => $invoiceId]);
                        self::safeLogModuleCall('Fallback-Exception-DB-UpdateOrder-InvoiceLink', ['orderId' => $orderId, 'invoiceId' => $invoiceId], ['error' => $apiEx->getMessage()]);
                    }
                }

                $orderJustCreated = true;
                self::safeLogModuleCall('handleInvoice - order created, will redirect immediately', [
                    'orderId' => $orderId,
                    'invoiceId' => $invoiceId,
                    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD']
                ]);
            }

            // Proceed to PaymentHood when:
            // 1. Form posts with our gateway (manual selection)
            // 2. Order was just created in this request (to avoid WHMCS order flow interference)
            // 3. Order exists and was created very recently (within 30 seconds - coming from order completion page)
            $shouldRedirectToPaymentHood = (
                ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['paymentmethod'] ?? '') === self::PAYMENTHOOD_GATEWAY)
                || $orderJustCreated
                || $orderIsRecent
            );

            if ($shouldRedirectToPaymentHood) {
                self::safeLogModuleCall('call payment api - start', [
                    'appId' => $appId,
                    'invoiceId' => $invoiceId,
                    'orderJustCreated' => $orderJustCreated,
                    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
                    'POST_data' => $_POST
                ]);

                $amount = $params['amount'];
                $currency = $params['currency'];
                $clientEmail = $params['clientdetails']['email'] ?? '';

                // Always return/callback to the invoice page context
                $callbackUrl = rtrim((string) self::getSystemUrl(), '/') . '/modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;

                // Detect subscription-style items to influence checkout UI only
                $hasSubscription = Capsule::table('tblinvoiceitems as ii')
                    ->leftJoin('tblhosting as h', 'h.id', '=', 'ii.relid')
                    ->where('ii.invoiceid', $invoiceId)
                    ->whereIn('ii.type', ['Hosting', 'Product', 'Product/Service'])
                    ->where(function ($q) {
                        $q->whereNull('h.billingcycle')
                            ->orWhereNotIn('h.billingcycle', ['Free', 'Free Account', 'One Time']);
                    })
                    ->exists();

                $postData = [
                    'referenceId' => (string) $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'autoCapture' => true,
                    'webhookUrl' => $callbackUrl,
                    'showAvailablePaymentMethodsInCheckout' => $hasSubscription,
                    'customerOrder' => [
                        'customer' => [
                            'customerId' => (string) $clientId,
                            'email' => $clientEmail,
                        ],
                    ],
                    'returnUrl' => $callbackUrl,
                ];

                $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/hosted-page", $postData, $token);
                self::safeLogModuleCall('result of create payment', ['invoiceId' => $invoiceId], $response);

                if (isset($response['Message']) && strpos($response['Message'], 'ProviderReferenceId already used') !== false) {
                    self::safeLogModuleCall('Duplicate Payment', $response);
                    // Let the callback resolve final status; do not cancel invoice here.
                    return '<p>Duplicate payment reference detected. Please refresh the invoice page; if the invoice is already paid, no action is required.</p>';
                }

                if (empty($response['redirectUrl'])) {
                    return '<p>Payment gateway returned invalid response.</p>';
                }

                self::safeLogModuleCall('redirect to hosted payment', ['url' => $response['redirectUrl'], 'invoiceId' => $invoiceId]);
                header('Location: ' . $response['redirectUrl']);
                exit;
            }

            // Initial render: show our Pay button UI
            return self::renderInvoiceUI((string) $invoiceId, $appId, $token);
        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_exception', $params, ['error' => $ex->getMessage()], $ex->getTraceAsString());
            return '<div class="alert alert-danger">paymentHood Error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
        }
    }

    public static function renderInvoiceUI($invoiceId, $appId, $token, $autoSubmit = false)
    {
        // Check if payment already exists
        $paymentStatus = self::checkInvoiceStatus($invoiceId, $appId, $token);

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
                $html = '<div class="alert alert-info">A payment session is already in progress for this invoice.</div>';
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
            $html = '<div class="alert alert-warning">This invoice cannot be paid via PaymentHood at the moment.</div>';
            return $html . self::loadHidePaymentMethodsJS();
        } else {
            // No payment found, show the payment button
            $systemUrl = self::getSystemUrl();
            $formAction = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

            $html = '<form id="paymenthood-form" method="post" action="' . htmlspecialchars($formAction) . '">
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
        try {
            // Get invoices due today that use paymentHood as gateway
            $invoices = Capsule::table('tblinvoices as i')
                ->join('tblinvoiceitems as ii', 'ii.invoiceid', '=', 'i.id')
                ->join('tblhosting as h', 'ii.relid', '=', 'h.id')
                ->where('i.status', 'Unpaid')
                ->where('ii.type', 'Hosting')
                ->whereNotIn('h.billingcycle', ['One Time', 'Free'])
                ->whereColumn('i.duedate', '=', 'h.nextduedate') // only auto-generated invoice
                ->select('i.id as invoiceId', 'i.userid', 'i.total')
                ->distinct()
                ->get();

            foreach ($invoices as $invoice) {
                $invoiceId = $invoice->invoiceId;
                $clientId = $invoice->userid;

                self::safeLogModuleCall(
                    'processUnpaidInvoices',
                    ['invoiceId' => $invoiceId, 'clientId' => $clientId]
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

            $postData = [
                "referenceId" => $invoiceId,
                "amount" => $amount,
                "autoCapture" => true,
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

    public static function getGatewayCredentials()
    {
        $rows = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'paymenthood')
            ->whereIn('setting', ['appId', 'token', 'webhookToken'])
            ->get()
            ->keyBy('setting');

        $appId = isset($rows['appId']) ? $rows['appId']->value : null;
        $token = isset($rows['token']) ? $rows['token']->value : null;
        $webhookToken = isset($rows['webhookToken']) ? $rows['webhookToken']->value : null;

        return ['appId' => $appId, 'token' => $token, 'webhookToken' => $webhookToken];
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

    public static function paymenthood_getPaymentAppBaseUrl(): string
    {
        return rtrim('https://appapi.paymenthood.com/api/', '/');
    }

    public static function paymenthood_grantAuthorizationUrl(): string
    {
        return rtrim('https://console.paymenthood.com/auth/signin', '/');
    }

    public static function paymenthood_getPaymentBaseUrl(): string
    {
        return rtrim('https://api.paymenthood.com/api/v1', '/');
    }
}
