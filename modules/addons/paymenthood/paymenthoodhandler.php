<?php
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

class PaymentHoodAppInactiveException extends \RuntimeException
{
    /** @var string|null */
    private $appId;

    public function __construct(?string $appId = null, string $message = 'PaymentHood app is inactive.')
    {
        parent::__construct($message !== '' ? $message : 'PaymentHood app is inactive.');
        $appId = is_string($appId) ? trim($appId) : '';
        $this->appId = $appId !== '' ? $appId : null;
    }

    public function getAppId(): ?string
    {
        return $this->appId;
    }
}

class PaymentHoodHandler
{
    const PAYMENTHOOD_GATEWAY = 'paymenthood';
    private const APP_INACTIVE_EXCEPTION_NAME = 'Paymenting.Service.Exceptions.AppInactiveException';
    private const APP_INACTIVE_CUSTOMER_MESSAGE = 'Payment service is temporarily unavailable. Please contact the administrator.';
    private const APP_INACTIVE_FLAG_SETTING = 'AppInactiveDetected';
    private const APP_INACTIVE_APP_ID_SETTING = 'AppInactiveAppId';
    private const APP_INACTIVE_MESSAGE_SETTING = 'AppInactiveMessage';
    private const APP_INACTIVE_AT_SETTING = 'AppInactiveAt';

    private static function toMoney($value): float
    {
        if ($value === null) {
            return 0.0;
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        return (float) $value;
    }

    /**
     * Extract total fee (appFee + providerFee) from PaymentHood feeBreakdown.
     * Treats null/missing values as 0. Returns 0.0 if feeBreakdown is absent.
     *
     * @param array|null $paymentData Full payment response from PaymentHood API
     * @return float Total fee (appFee + providerFee)
     */
    public static function extractTotalFee($paymentData): float
    {
        if (!is_array($paymentData)) {
            return 0.0;
        }

        $feeBreakdown = $paymentData['feeBreakdown'] ?? null;
        if (!is_array($feeBreakdown)) {
            return 0.0;
        }

        $appFee = self::toMoney($feeBreakdown['appFee'] ?? null);
        $providerFee = self::toMoney($feeBreakdown['providerFee'] ?? null);

        $totalFee = $appFee + $providerFee;

        // Sanity: fee should not be negative
        return $totalFee >= 0 ? round($totalFee, 2) : 0.0;
    }

    private static function getInvoiceCurrencyCode(int $invoiceId): ?string
    {
        if ($invoiceId <= 0) {
            return null;
        }

        try {
            $currencyCode = Capsule::table('tblinvoices as i')
                ->join('tblcurrencies as c', 'c.id', '=', 'i.currency')
                ->where('i.id', $invoiceId)
                ->value('c.code');

            $currencyCode = is_string($currencyCode) ? trim($currencyCode) : '';
            return $currencyCode !== '' ? $currencyCode : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a best-effort PaymentHood customerOrder payload using WHMCS data.
     * Skips fields when WHMCS doesn't have the data.
     */
    private static function buildCustomerOrderPayload(
        int $clientId,
        int $invoiceId,
        ?string $currency,
        $amount,
        array $params = []
    ): array {
        $customerOrder = [];

        $currency = is_string($currency) ? trim($currency) : '';
        if ($currency === '' && $invoiceId > 0) {
            $currency = self::getInvoiceCurrencyCode($invoiceId) ?? '';
        }

        // Customer info (prefer WHMCS gateway params; fall back to DB)
        $client = $params['clientdetails'] ?? [];
        if (!is_array($client) || empty($client)) {
            try {
                $row = Capsule::table('tblclients')->where('id', $clientId)->first();
                if ($row) {
                    $client = [
                        'userid' => $row->id ?? $clientId,
                        'firstname' => $row->firstname ?? null,
                        'lastname' => $row->lastname ?? null,
                        'email' => $row->email ?? null,
                        'phonenumber' => $row->phonenumber ?? null,
                        'address1' => $row->address1 ?? null,
                        'address2' => $row->address2 ?? null,
                        'postcode' => $row->postcode ?? null,
                        'city' => $row->city ?? null,
                        'state' => $row->state ?? null,
                        'country' => $row->country ?? null,
                        'datecreated' => $row->datecreated ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $customer = [];
        if ($clientId > 0) {
            $customer['customerId'] = (string) $clientId;
        }

        $accountCreated = $client['datecreated'] ?? $client['dateCreated'] ?? null;
        if (is_string($accountCreated) && trim($accountCreated) !== '') {
            $customer['accountCreatedTime'] = trim($accountCreated);
        }

        $firstName = $client['firstname'] ?? $client['firstName'] ?? null;
        $lastName = $client['lastname'] ?? $client['lastName'] ?? null;
        if (is_string($firstName) && trim($firstName) !== '') {
            $customer['firstName'] = trim($firstName);
        }
        if (is_string($lastName) && trim($lastName) !== '') {
            $customer['lastName'] = trim($lastName);
        }

        $email = $client['email'] ?? null;
        if (is_string($email) && trim($email) !== '') {
            $customer['email'] = trim($email);
        }

        $phone = $client['phonenumber'] ?? $client['phoneNumber'] ?? null;
        if (is_string($phone) && trim($phone) !== '') {
            $customer['phoneNumber'] = trim($phone);
        }

        // Address
        $address = [];
        $a1 = $client['address1'] ?? $client['streetAddressLine1'] ?? null;
        $a2 = $client['address2'] ?? $client['streetAddressLine2'] ?? null;
        $zip = $client['postcode'] ?? $client['zipCode'] ?? null;
        $city = $client['city'] ?? null;
        $state = $client['state'] ?? null;
        $countryCode = $client['country'] ?? $client['countryCode'] ?? null;

        if (is_string($a1) && trim($a1) !== '') {
            $address['streetAddressLine1'] = trim($a1);
        }
        if (is_string($a2) && trim($a2) !== '') {
            $address['streetAddressLine2'] = trim($a2);
        }
        if (is_string($zip) && trim($zip) !== '') {
            $address['zipCode'] = trim($zip);
        }
        if (is_string($city) && trim($city) !== '') {
            $address['city'] = trim($city);
        }
        if (is_string($state) && trim($state) !== '') {
            $address['state'] = trim($state);
        }
        if (is_string($countryCode) && trim($countryCode) !== '') {
            $address['countryCode'] = trim($countryCode);
            // WHMCS typically stores country as ISO2; if we don't know the name, keep it consistent
            $address['country'] = trim($countryCode);
        }

        if (!empty($address)) {
            $customer['address'] = $address;
        }

        if (!empty($customer)) {
            $customerOrder['customer'] = $customer;
        }

        // Order identifiers/description
        if ($invoiceId > 0) {
            $customerOrder['customId'] = (string) $invoiceId;
        }

        // Amount breakdown + items (invoice-aware when possible)
        $invoice = null;
        if ($invoiceId > 0) {
            try {
                $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            } catch (\Throwable $e) {
                $invoice = null;
            }
        }

        $subtotal = $invoice ? self::toMoney($invoice->subtotal ?? 0) : 0.0;
        $tax1 = $invoice ? self::toMoney($invoice->tax ?? 0) : 0.0;
        $tax2 = $invoice ? self::toMoney($invoice->tax2 ?? 0) : 0.0;
        $credit = $invoice ? self::toMoney($invoice->credit ?? 0) : 0.0;
        $invoiceTotal = $invoice ? self::toMoney($invoice->total ?? 0) : 0.0;
        $totalTax = $tax1 + $tax2;

        // PaymentHood validates: apiTotal + totalTax + shipping + handling - discount - shippingDiscount == paymentAmount
        // WHMCS invoice: invoiceTotal = subtotal + totalTax - credit
        //                subtotal      + totalTax            - credit  == paymentAmount ✓
        // So apiTotal = subtotal (pre-tax), totalTax separate, discount = credit.
        $hasTax = $totalTax > 0.0;
        if ($invoice) {
            $apiTotal = $subtotal;
            $discount = $credit;
        } else {
            // No invoice breakdown — flat charge with no tax/discount split.
            $apiTotal = self::toMoney($amount);
            $discount = 0.0;
            $hasTax = false;
            $totalTax = 0.0;
        }

        // Only include totalTax when > 0; sending 0 (not null) triggers PaymentHood's
        // per-item tax checks even when there is no tax to distribute.
        $amountPayload = [
            'total' => $apiTotal,
            'handling' => 0,
            'insurance' => 0,
            'discount' => $discount > 0.0 ? $discount : 0,
            'shipping' => 0,
            'shippingDiscount' => 0,
        ];
        if ($hasTax) {
            $amountPayload['totalTax'] = $totalTax;
        }
        $customerOrder['amount'] = $amountPayload;

        // Build items from invoice lines when available.
        // ValidateAmount rules (only enforced when Items is non-null):
        //   • If TotalTax != null → every item.Tax must be non-null, Sum(item.Tax × qty) = TotalTax
        //   • Sum((item.Amount + item.Tax) × qty) = Total + TotalTax
        $items = [];
        if ($invoiceId > 0 && $invoice) {
            try {
                $invoiceItems = Capsule::table('tblinvoiceitems')
                    ->where('invoiceid', $invoiceId)
                    ->orderBy('id', 'asc')
                    ->get();

                // Pre-compute taxable subtotal for proportional tax allocation.
                $taxableSubtotal = 0.0;
                foreach ($invoiceItems as $ii) {
                    if ((string) ($ii->taxed ?? '') === '1') {
                        $taxableSubtotal += self::toMoney($ii->amount ?? 0);
                    }
                }

                $builtItems = [];
                $taxAllocated = 0.0;
                $lastTaxedIdx = -1;

                foreach ($invoiceItems as $ii) {
                    $desc = is_string($ii->description ?? null) ? trim((string) $ii->description) : '';
                    $lineAmount = self::toMoney($ii->amount ?? 0);
                    $type = is_string($ii->type ?? null) ? trim((string) $ii->type) : '';
                    $relid = (int) ($ii->relid ?? 0);

                    if ($desc === '' && $lineAmount == 0.0) {
                        continue;
                    }

                    // Tax is always present on every item (0 when not taxed) because
                    // PaymentHood rejects the payload if any item.Tax is null while
                    // Amount.TotalTax is non-null.
                    $taxForItem = 0.0;
                    if ($hasTax && (string) ($ii->taxed ?? '') === '1' && $taxableSubtotal > 0.0) {
                        $taxForItem = round(($lineAmount / $taxableSubtotal) * $totalTax, 2);
                        $taxAllocated += $taxForItem;
                        $lastTaxedIdx = count($builtItems);
                    }

                    // Category mapping (PaymentProviderCustomerOrderItemCategory).
                    $typeLower = strtolower($type);
                    if (in_array($typeLower, ['domain', 'hosting', 'addon', 'upgrade'], true)) {
                        $category = 'DigitalGoods';
                    } elseif ($typeLower === 'addfunds') {
                        $category = 'Service';
                    } else {
                        $category = 'DigitalGoods'; // safest default for WHMCS
                    }

                    $item = [
                        'name' => ($desc !== '' ? $desc : ($type !== '' ? $type : 'Item')),
                        'description' => '',
                        'amount' => $lineAmount,
                        'quantity' => 1,
                        'tax' => $taxForItem,
                        'sku' => ($type !== '' && $relid > 0) ? ($type . ':' . $relid) : '',
                        'category' => $category,
                    ];

                    if ($currency !== '') {
                        $item['currency'] = $currency;
                    }

                    $builtItems[] = $item;
                }

                // Fix rounding so Sum(item.Tax × qty) == totalTax exactly.
                if ($hasTax && $lastTaxedIdx >= 0) {
                    $remainder = round($totalTax - $taxAllocated, 2);
                    if (abs($remainder) >= 0.01) {
                        $builtItems[$lastTaxedIdx]['tax'] = round(
                            (float) $builtItems[$lastTaxedIdx]['tax'] + $remainder,
                            2
                        );
                    }
                }

                $items = $builtItems;
            } catch (\Throwable $e) {
                $items = [];
            }
        }

        if (!empty($items)) {
            $customerOrder['items'] = array_values($items);
            if (!isset($customerOrder['description']) && isset($items[0]['name'])) {
                $customerOrder['description'] = (string) $items[0]['name'];
            }
        }

        return $customerOrder;
    }

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

    public static function isAdminArea(): bool
    {
        return defined('ADMINAREA') && (bool) ADMINAREA;
    }

    public static function getCustomerAppInactiveMessage(): string
    {
        return self::APP_INACTIVE_CUSTOMER_MESSAGE;
    }

    public static function getManageLicensesUrl(?string $appId): ?string
    {
        $appId = is_string($appId) ? trim($appId) : '';
        if ($appId === '') {
            return null;
        }

        return rtrim(self::paymenthood_ConsoleUrl(), '/') . '/' . urlencode($appId) . '/licenses';
    }

    public static function getGatewaySetting(string $setting)
    {
        $setting = trim($setting);
        if ($setting === '') {
            return null;
        }

        try {
            return Capsule::table('tblpaymentgateways')
                ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                ->whereRaw('LOWER(setting) = ?', [strtolower($setting)])
                ->orderBy('id', 'desc')
                ->value('value');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function saveGatewaySetting(string $setting, $value): void
    {
        $setting = trim($setting);
        if ($setting === '') {
            return;
        }

        try {
            Capsule::connection()->transaction(function () use ($setting, $value) {
                $rows = Capsule::table('tblpaymentgateways')
                    ->where('gateway', self::PAYMENTHOOD_GATEWAY)
                    ->whereRaw('LOWER(setting) = ?', [strtolower($setting)])
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
                    return;
                }

                Capsule::table('tblpaymentgateways')->insert([
                    'gateway' => self::PAYMENTHOOD_GATEWAY,
                    'setting' => $setting,
                    'value' => $value,
                ]);
            });
        } catch (\Throwable $e) {
            self::safeLogModuleCall('save_gateway_setting_error', [
                'setting' => $setting,
            ], [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function clearAppInactiveState(): void
    {
        self::saveGatewaySetting(self::APP_INACTIVE_FLAG_SETTING, '0');
        self::saveGatewaySetting(self::APP_INACTIVE_APP_ID_SETTING, '');
        self::saveGatewaySetting(self::APP_INACTIVE_MESSAGE_SETTING, '');
        self::saveGatewaySetting(self::APP_INACTIVE_AT_SETTING, '');
    }

    public static function markAppInactive(?string $appId, $value): void
    {
        $message = self::extractAppInactiveErrorMessage($value);
        $appId = is_string($appId) ? trim($appId) : '';

        self::saveGatewaySetting(self::APP_INACTIVE_FLAG_SETTING, '1');
        self::saveGatewaySetting(self::APP_INACTIVE_APP_ID_SETTING, $appId);
        self::saveGatewaySetting(self::APP_INACTIVE_MESSAGE_SETTING, $message);
        self::saveGatewaySetting(self::APP_INACTIVE_AT_SETTING, date('Y-m-d H:i:s'));
    }

    public static function getAppInactiveState(): array
    {
        $flag = self::getGatewaySetting(self::APP_INACTIVE_FLAG_SETTING);
        $isActiveFlag = is_string($flag) ? trim($flag) : (string) $flag;

        return [
            'isInactive' => in_array(strtolower($isActiveFlag), ['1', 'on', 'yes', 'true'], true),
            'appId' => self::getGatewaySetting(self::APP_INACTIVE_APP_ID_SETTING),
            'message' => self::getGatewaySetting(self::APP_INACTIVE_MESSAGE_SETTING),
            'detectedAt' => self::getGatewaySetting(self::APP_INACTIVE_AT_SETTING),
        ];
    }

    public static function refreshAppInactiveState(): array
    {
        $state = self::getAppInactiveState();

        $credentials = self::getGatewayCredentials();
        $appId = isset($credentials['appId']) && is_string($credentials['appId']) ? trim($credentials['appId']) : '';
        $token = isset($credentials['token']) && is_string($credentials['token']) ? trim($credentials['token']) : '';

        if ($appId === '') {
            $appId = isset($state['appId']) && is_string($state['appId']) ? trim($state['appId']) : '';
        }

        if ($appId === '' || $token === '') {
            return $state;
        }

        $url = self::paymenthood_getPaymentAppBaseUrl() . '/apps/' . urlencode($appId);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        self::safeLogModuleCall('refresh_app_inactive_state', [
            'appId' => $appId,
            'url' => $url,
        ], [
            'httpCode' => $httpCode,
            'curlError' => $curlError !== '' ? $curlError : null,
            'responseSnippet' => is_string($response) ? substr($response, 0, 500) : null,
        ]);

        if ($response !== false && self::isAppInactiveError($response)) {
            self::markAppInactive($appId, $response);
            return self::getAppInactiveState();
        }

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            $isActive = null;
            if (is_array($decoded) && array_key_exists('isActive', $decoded)) {
                $isActive = filter_var($decoded['isActive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive === null) {
                    $isActive = (bool) $decoded['isActive'];
                }
            }

            if ($isActive === false) {
                $licenseExpirationTime = '';
                if (is_array($decoded) && isset($decoded['licenseExpirationTime']) && is_string($decoded['licenseExpirationTime'])) {
                    $licenseExpirationTime = trim($decoded['licenseExpirationTime']);
                }

                $message = 'PaymentHood app is inactive.';
                if ($licenseExpirationTime !== '') {
                    $message .= ' License expiration time: ' . $licenseExpirationTime;
                }

                self::markAppInactive($appId, $message);
                return self::getAppInactiveState();
            }

            self::clearAppInactiveState();
            return self::getAppInactiveState();
        }

        return $state;
    }

    private static function extractAppIdFromUrl(string $url): ?string
    {
        if (preg_match('~/apps/([^/]+)~', $url, $matches) !== 1) {
            return null;
        }

        $appId = urldecode((string) ($matches[1] ?? ''));
        $appId = trim($appId);
        return $appId !== '' ? $appId : null;
    }

    private static function collectNestedStrings($value): array
    {
        $strings = [];

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $strings[] = $trimmed;
            }
            return $strings;
        }

        if (!is_array($value)) {
            return $strings;
        }

        foreach ($value as $item) {
            foreach (self::collectNestedStrings($item) as $nested) {
                $strings[] = $nested;
            }
        }

        return $strings;
    }

    public static function isAppInactiveError($value): bool
    {
        $haystacks = [];

        if ($value instanceof \Throwable) {
            $haystacks[] = $value->getMessage();
        } elseif (is_string($value)) {
            $haystacks[] = $value;
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $haystacks = array_merge($haystacks, self::collectNestedStrings($decoded));
            }
        } elseif (is_array($value)) {
            $haystacks = self::collectNestedStrings($value);
        }

        foreach ($haystacks as $haystack) {
            if (!is_string($haystack) || $haystack === '') {
                continue;
            }

            if (stripos($haystack, self::APP_INACTIVE_EXCEPTION_NAME) !== false || stripos($haystack, 'AppInactiveException') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function extractAppInactiveErrorMessage($value): string
    {
        $haystacks = [];

        if ($value instanceof \Throwable) {
            $haystacks[] = $value->getMessage();
        } elseif (is_string($value)) {
            $haystacks[] = trim($value);
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $haystacks = array_merge($haystacks, self::collectNestedStrings($decoded));
            }
        } elseif (is_array($value)) {
            $haystacks = self::collectNestedStrings($value);
        }

        foreach ($haystacks as $haystack) {
            if (!is_string($haystack)) {
                continue;
            }

            $haystack = trim($haystack);
            if ($haystack === '') {
                continue;
            }

            if (stripos($haystack, self::APP_INACTIVE_EXCEPTION_NAME) !== false || stripos($haystack, 'AppInactiveException') !== false) {
                return $haystack;
            }
        }

        return 'PaymentHood app is inactive.';
    }

    private static function createAppInactiveException(?string $appId, $value): PaymentHoodAppInactiveException
    {
        self::markAppInactive($appId, $value);
        return new PaymentHoodAppInactiveException($appId, self::extractAppInactiveErrorMessage($value));
    }

    public static function renderAppInactiveError(?string $appId = null, ?string $details = null): string
    {
        $isAdminArea = self::isAdminArea();
        $errorMessage = $isAdminArea
            ? 'PaymentHood app is inactive. Please review the license in the PaymentHood console.'
            : self::getCustomerAppInactiveMessage();

        $details = $isAdminArea && is_string($details) ? trim($details) : '';

        return self::renderTemplate('error-general', [
            'errorMessage' => $errorMessage,
            'details' => $details !== '' ? $details : null,
            'actionUrl' => $isAdminArea ? self::getManageLicensesUrl($appId) : null,
            'actionLabel' => 'Manage PaymentHood License',
        ]);
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
     * Render a PHP template from modules/addons/paymenthood/templates/ and return its output.
     * Variables in $vars are extracted into the template's local scope.
     *
     * @param string $template  Template filename without .php extension
     * @param array  $vars      Variables to expose inside the template
     * @return string           Rendered HTML
     */
    public static function renderTemplate(string $template, array $vars = []): string
    {
        $templatePath = __DIR__ . '/templates/' . $template . '.php';
        if (!is_file($templatePath)) {
            return '<!-- PaymentHood: template "' . htmlspecialchars($template) . '" not found -->';
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
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

    private static function ensureInvoiceFunctionsLoaded(): void
    {
        if (!function_exists('addInvoicePayment') && defined('ROOTDIR')) {
            $invoiceFunctionsPath = ROOTDIR . '/includes/invoicefunctions.php';
            if (is_file($invoiceFunctionsPath)) {
                require_once $invoiceFunctionsPath;
            }
        }
    }

    public static function recordInvoicePayment(
        int $invoiceId,
        ?string $transactionId,
        $amount,
        $fee = 0.0,
        string $gateway = self::PAYMENTHOOD_GATEWAY
    ): void {
        self::ensureInvoiceFunctionsLoaded();

        if (!function_exists('addInvoicePayment')) {
            throw new \RuntimeException('WHMCS addInvoicePayment() is not available in the current context.');
        }

        addInvoicePayment(
            $invoiceId,
            $transactionId ?: '',
            self::toMoney($amount),
            self::toMoney($fee),
            $gateway
        );
    }

    public static function handleInvoice(array $params)
    {
        $appId = null;

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
                        'clientId' => $clientId,
                        '_note' => "Invoice #$invoiceId has no linked order. Searching for an unlinked (orphan) order for client #$clientId to associate with this invoice.",
                    ], [
                        'orphanOrderFound' => $orphanOrder ? 1 : 0,
                        'orphanOrderId' => $orphanOrder ? $orphanOrder->id : null,
                        'orphanOrderPaymentMethod' => $orphanOrder ? $orphanOrder->paymentmethod : null,
                        '_result' => $orphanOrder
                            ? "Found orphan order #{$orphanOrder->id} (method: {$orphanOrder->paymentmethod}) — will be linked to invoice #$invoiceId."
                            : "No orphan order found for client #$clientId. A new order will be created and linked to invoice #$invoiceId.",
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
                            'orphanOrderId' => $orphanOrder->id,
                            '_note' => "Orphan order #$orderId was unlinked. It has now been associated with invoice #$invoiceId and its payment method set to PaymentHood.",
                        ], [
                            '_result' => "Order #$orderId is now linked to invoice #$invoiceId. Customer will be redirected to checkout.",
                        ]);
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
                            'orderNumber' => $orderNumber,
                            '_note' => "No existing order found for invoice #$invoiceId. A new pending order #$orderId (order number: $orderNumber) has been created and linked to this invoice.",
                        ], [
                            '_result' => "New order #$orderId created and linked to invoice #$invoiceId. Customer will be redirected to checkout.",
                        ]);
                    }
                }
            }

            // Cart checkout: create order via AddOrder only if there are products
            if ($isCartCheckout) {
                $apiParams = [
                    'clientid' => $clientId,
                    'paymentmethod' => self::PAYMENTHOOD_GATEWAY,
                    'status' => 'Pending',
                    'sendemail' => true,
                    'sendinvoice' => true,
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
                    return self::renderTemplate('error-cart-order');
                }
            }

            // Detect if the customer is landing back from a PaymentHood attempt
            // (success, failure, cancel, pending) — in those cases show the UI,
            // don't loop them back to PaymentHood immediately.
            $returningFromGateway = !empty($_GET['paymentsuccess'])
                || !empty($_GET['paymentfailed'])
                || !empty($_GET['paymentcancelled'])
                || !empty($_GET['paymentpending']);

            // Fetch invoice status for the redirect decision.
            $invoiceStatus = '';
            if ($isInvoicePayment) {
                $invoiceStatus = (string) (Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->value('status') ?? '');
            }

            // Determine whether we are on the billing invoice detail page (viewinvoice.php).
            // On that page we must NEVER auto-redirect — the customer must see the invoice
            // and explicitly click Pay. Auto-redirect only applies to the checkout flow
            // (cart confirmation page, etc.) where the customer has already committed.
            $isViewInvoicePage = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'viewinvoice.php';

            // Redirect to PaymentHood when:
            // 1. Customer explicitly submitted the payment form (POST with paymentmethod=paymenthood)
            //    — covers both the invoice Pay button and the cart checkout submit.
            // 2. Customer is in the checkout flow (not on viewinvoice.php), invoice is Unpaid,
            //    and they are not returning from a previous gateway attempt.
            $shouldRedirect = (
                ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['paymentmethod'] ?? '') === self::PAYMENTHOOD_GATEWAY) ||
                (!$isViewInvoicePage && $isInvoicePayment && $invoiceStatus === 'Unpaid' && !$returningFromGateway)
            );

            if ($shouldRedirect) {
                $amount = $params['amount'];
                $currency = $params['currency'];
                $clientEmail = $params['clientdetails']['email'] ?? '';

                // Return cached redirectUrl immediately if this invoice already has
                // a hosted-page session — prevents duplicate referenceId API errors
                // when WHMCS calls paymenthood_link() more than once per request.
                $sessionCacheKey = 'paymenthood_redirect_' . $invoiceId;
                $cachedRedirectUrl = $_SESSION[$sessionCacheKey] ?? null;
                if (!empty($cachedRedirectUrl)) {
                    return '<style>html,body{display:none!important}</style>'
                        . '<script>window.location.replace(' . json_encode($cachedRedirectUrl) . ');</script>';
                }

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

                $callbackUrl = rtrim(self::getSystemUrl(), '/') . '/modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;

                $customerOrder = self::buildCustomerOrderPayload($clientId, $invoiceId, (string) $currency, $amount, $params);

                $postData = [
                    'referenceId' => (string) $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'autoCapture' => true,
                    'webhookUrl' => $callbackUrl,
                    'customerOrder' => $customerOrder,
                    'returnUrl' => $callbackUrl,
                    'showPayRecurringInCheckout' => $hasRecurringItem,
                ];

                // Attach selected payment profile from session (set by checkout page profile picker)
                $selectedProfileId = $_SESSION['paymenthood_profile_id'] ?? null;
                if (!empty($selectedProfileId) && $selectedProfileId !== 'creditcard') {
                    $postData['paymentProfileId'] = (string) $selectedProfileId;
                }

                try {
                    $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/hosted-page", $postData, $token);
                } catch (\Throwable $apiEx) {
                    $msg = (string) $apiEx->getMessage();
                    $needle = 'Can not find any profile for the app for specific currency';

                    if ($msg !== '' && stripos($msg, $needle) !== false) {
                        $manageUrl = rtrim(self::paymenthood_ConsoleUrl(), '/') . '/' . urlencode((string) $appId) . '/gateways';
                        $sandboxNotice = !empty($useSandbox) ? self::renderTemplate('sandbox-notice') : '';
                        return $sandboxNotice . self::renderTemplate('error-currency', ['manageUrl' => $manageUrl]);
                    }

                    throw $apiEx;
                }
                self::safeLogModuleCall('handler_payment_created', [
                    'invoiceId' => $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    '_note' => "PaymentHood hosted checkout session created for invoice #$invoiceId ($amount $currency). Customer is now being redirected to the PaymentHood checkout page.",
                ], [
                    'paymentId' => $response['paymentId'] ?? null,
                    'redirectUrl' => $response['redirectUrl'] ?? null,
                    '_result' => 'Customer redirected to PaymentHood. Waiting for payment completion or webhook callback.',
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

                // Store the redirectUrl in session so any subsequent render of
                // paymenthood_link() for the same invoice reuses it instead of
                // making a second hosted-page API call (which PaymentHood rejects
                // as a duplicate referenceId).
                $_SESSION[$sessionCacheKey] = $response['redirectUrl'];

                // Cannot use header()+exit — that kills WHMCS before it sends
                // checkout emails (Order Confirmation, Customer Invoice, New Order
                // Notification). Instead, hide the entire page immediately via CSS
                // and redirect via JS. The user sees nothing; PHP completes normally
                // so WHMCS finishes sending all emails.
                return '<style>html,body{display:none!important}</style>'
                    . '<script>window.location.replace(' . json_encode($response['redirectUrl']) . ');</script>';
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
            $sandboxNotice = $isSandbox ? self::renderTemplate('sandbox-notice') : '';

            if ($ex instanceof PaymentHoodAppInactiveException || self::isAppInactiveError($ex)) {
                $inactiveAppId = $ex instanceof PaymentHoodAppInactiveException
                    ? $ex->getAppId()
                    : (is_string($appId) ? $appId : null);

                return $sandboxNotice . self::renderAppInactiveError($inactiveAppId, self::extractAppInactiveErrorMessage($ex));
            }

            return $sandboxNotice . self::renderTemplate('error-general', ['errorMessage' => $ex->getMessage()]);
        }
    }

    public static function renderInvoiceUI($invoiceId, $appId, $token, $autoSubmit = false, $useSandbox = false)
    {
        // Check if payment already exists
        $paymentStatus = self::checkInvoiceStatus($invoiceId, $appId, $token);

        $sandboxNotice = $useSandbox ? self::renderTemplate('sandbox-notice') : '';

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
                return $sandboxNotice . self::renderTemplate('invoice-continue', ['redirectUrl' => $redirectUrl]);
            }

            // Fallback: show a warning if redirect URL is missing
            self::safeLogModuleCall('handler_payment_missing_redirect', [
                'invoiceId' => $invoiceId
            ], [
                'error' => 'Payment exists but redirectUrl missing',
                'paymentId' => $paymentStatus['paymentId'] ?? null
            ]);
            return $sandboxNotice . self::renderTemplate('invoice-missing-redirect');
        } else {
            // No payment found, show the payment button
            $systemUrl = self::getSystemUrl();
            $formAction = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

            $html = $sandboxNotice . self::renderTemplate('invoice-pay-form', [
                'formAction' => $formAction,
                'invoiceId' => (string) $invoiceId,
                'gateway' => self::PAYMENTHOOD_GATEWAY,
            ]);

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

            return $html . $autoSubmitJs;
        }
    }

    public static function processUnpaidInvoices()
    {
        self::safeLogModuleCall('handler_cron_unpaid_invoices_start', [], [
            '_note' => 'PaymentHood cron job started. Scanning for unpaid renewal invoices (due today) to auto-charge via saved payment profiles.',
        ]);

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
                ->where('i.duedate', '<=', date('Y-m-d')) // only charge invoices that are due today or overdue
                ->where('i.date', '<=', date('Y-m-d H:i:s', strtotime('-2 hours'))) // skip invoices created in the last 2 hours
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
                                self::recordInvoicePayment(
                                    (int) $invoiceId,
                                    isset($result['paymentId']) ? (string) $result['paymentId'] : null,
                                    $invoice->total,
                                    0,
                                    self::PAYMENTHOOD_GATEWAY
                                );

                                self::safeLogModuleCall('Invoice payment recorded', [
                                    'invoiceId' => $invoiceId,
                                    'transactionId' => $result['paymentId'] ?? null,
                                ], [
                                    'gateway' => self::PAYMENTHOOD_GATEWAY,
                                ]);
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

            // Pre-check: does PaymentHood already have a payment for this referenceId?
            // This handles the case where the customer visited the invoice page (creating a
            // hosted-page session) but never completed payment, burning the referenceId.
            $existingUrl = self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/referenceId:$invoiceId";
            $existingPayment = self::callApi($existingUrl, [], $token, 'GET');
            $existingHttpCode = $existingPayment['_httpCode'] ?? null;

            if ($existingHttpCode !== 404 && !empty($existingPayment['paymentState'])) {
                $paymentState = $existingPayment['paymentState'];
                self::safeLogModuleCall('createAutoPayment - pre-check found existing payment', [
                    'invoiceId' => $invoiceId,
                    'paymentState' => $paymentState,
                    'hasRedirectUrl' => !empty($existingPayment['redirectUrl']),
                ]);

                if ($paymentState === 'Captured') {
                    return [
                        'status' => 'paid',
                        'paymentId' => $existingPayment['paymentId'] ?? $existingPayment['id'] ?? null,
                    ];
                }

                if ($paymentState === 'Failed') {
                    return [
                        'status' => 'error',
                        'rawdata' => "Auto-payment skipped: existing payment for invoice #{$invoiceId} is in Failed state and PaymentHood rejects reuse of the same referenceId.",
                    ];
                }

                // Pending — distinguish abandoned hosted-page from genuine auto-payment in-flight
                if (!empty($existingPayment['redirectUrl'])) {
                    return [
                        'status' => 'error',
                        'rawdata' => "Auto-payment skipped: invoice #{$invoiceId} has an abandoned hosted-page session in PaymentHood (state: {$paymentState}). The customer must complete payment manually or the session must expire.",
                    ];
                }

                // Genuine auto-payment in-flight — webhook is still expected
                return [
                    'status' => 'success',
                    'paymentId' => $existingPayment['paymentId'] ?? $existingPayment['id'] ?? null,
                ];
            }

            // No existing payment — proceed to create one
            $invoiceIdInt = (int) $invoiceId;
            $currency = self::getInvoiceCurrencyCode($invoiceIdInt);
            $customerOrder = self::buildCustomerOrderPayload((int) $clientId, $invoiceIdInt, $currency, $amount, []);

            self::safeLogModuleCall('createAutoPayment', ['appId' => $appId]);
            $callbackUrl = rtrim(self::getSystemUrl(), '/') . '/modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;

            $postData = [
                "referenceId" => $invoiceId,
                "amount" => $amount,
                "currency" => $currency,
                "autoCapture" => true,
                "webhookUrl" => $callbackUrl,
                "customerOrder" => $customerOrder,
            ];

            // Remove currency if unknown (avoid sending null/empty to API)
            if (empty($postData['currency'])) {
                unset($postData['currency']);
            }

            $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/auto-payment", $postData, $token);
            self::safeLogModuleCall('createAutoPayment - result', $response);

            $httpCode = $response['_httpCode'] ?? null;

            // Fallback: handle unexpected duplicate reference response just in case
            if (isset($response['Message']) && strpos($response['Message'], 'ProviderReferenceId already used') !== false) {
                self::safeLogModuleCall('createAutoPayment - unexpected duplicate after pre-check', $response);
                return [
                    'status' => 'error',
                    'rawdata' => "Auto-payment failed: referenceId already used for invoice #{$invoiceId} despite pre-check passing.",
                ];
            }

            return [
                'status' => 'success',
                'paymentId' => $response['paymentId'] ?? $response['id'] ?? null,
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
            $appId = self::extractAppIdFromUrl($url);
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
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response !== false && self::isAppInactiveError($response)) {
                self::safeLogModuleCall('handler_api_app_inactive', [
                    'url' => $url,
                    'method' => $method,
                    'appId' => $appId,
                ], [
                    'httpCode' => $httpCode,
                    'response' => substr((string) $response, 0, 500),
                ]);
                throw self::createAppInactiveException($appId, $response);
            }

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                self::clearAppInactiveState();
            }

            if ($httpCode === 404) {
                self::safeLogModuleCall('API not found (404)', ['url' => $url], ['httpCode' => $httpCode, 'response' => $response]);
                return [
                    '_httpCode' => 404,
                    '_rawResponse' => $response,
                ];
            }

            if ($response === false) {
                throw new \Exception($curlError !== '' ? $curlError : 'PaymentHood API call failed');
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
        $useSandbox = self::isSandboxModeEnabled();
        $appIdSetting = $useSandbox ? 'SandboxAppId' : 'LiveAppId';
        $tokenSetting = $useSandbox ? 'SandboxAppToken' : 'LiveAppToken';

        // Fetch credentials as stored in tblpaymentgateways.
        $rows = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'paymenthood')
            ->whereIn('setting', [$appIdSetting, $tokenSetting, 'webhookToken', 'licenseId'])
            ->get()
            ->keyBy('setting');

        $appId = isset($rows[$appIdSetting]) ? $rows[$appIdSetting]->value : null;
        $token = isset($rows[$tokenSetting]) ? $rows[$tokenSetting]->value : null;
        $webhookToken = isset($rows['webhookToken']) ? $rows['webhookToken']->value : null;
        $licenseId = isset($rows['licenseId']) ? $rows['licenseId']->value : null;
        return ['appId' => $appId, 'token' => $token, 'webhookToken' => $webhookToken, 'licenseId' => $licenseId, 'useSandbox' => $useSandbox];
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

    // ── Refund two-factor authentication ────────────────────────────────
    //
    // The refund and mark-as-refund endpoints are 2FA-protected. They answer
    // with a typed exception envelope rather than a plain HTTP status:
    //
    //   {"TypeName":"NeedToActive2FaException", "TypeFullName":"...", ...}
    //   {"TypeName":"Invalid2FaException",      "TypeFullName":"...", ...}
    //
    // NeedToActive2Fa  -> the operator has no authenticator enrolled at all.
    //                     Nothing the module can do; they must enrol first.
    // Invalid2Fa       -> enrolled, but no/incorrect otpCode was sent.
    //                     Recoverable: prompt for a code and retry.

    const REFUND_2FA_NEEDS_ACTIVATION = 'needs_activation';
    const REFUND_2FA_INVALID_CODE     = 'invalid_code';

    /** Session key holding a one-shot prompt flag for the admin UI. */
    const REFUND_2FA_SESSION_KEY = 'paymenthood_refund_2fa';

    /**
     * Classify a PaymentHood API response as a 2FA failure.
     *
     * @param  mixed $response Decoded array or raw response body.
     * @return string|null One of the REFUND_2FA_* constants, or null.
     */
    public static function detectTwoFactorError($response)
    {
        $typeNames = [];
        $raw = '';

        if (is_string($response)) {
            $raw = $response;
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $response = $decoded;
            }
        }

        if (is_array($response)) {
            foreach (['TypeName', 'TypeFullName', 'typeName', 'typeFullName'] as $key) {
                if (isset($response[$key]) && is_string($response[$key])) {
                    $typeNames[] = $response[$key];
                }
            }
            if ($raw === '') {
                $raw = (string) json_encode($response);
            }
        }

        // Prefer the typed field; fall back to the raw body so a wrapped or
        // re-serialised envelope is still recognised.
        $haystacks = $typeNames !== [] ? $typeNames : [$raw];

        foreach ($haystacks as $haystack) {
            if (stripos($haystack, 'NeedToActive2FaException') !== false) {
                return self::REFUND_2FA_NEEDS_ACTIVATION;
            }
        }

        foreach ($haystacks as $haystack) {
            if (stripos($haystack, 'Invalid2FaException') !== false) {
                return self::REFUND_2FA_INVALID_CODE;
            }
        }

        return null;
    }

    /**
     * Read the authenticator code the operator supplied on the refund form.
     *
     * The field is injected into WHMCS's own refund form by the admin hook, so
     * it arrives in the same POST that triggers paymenthood_refund().
     */
    public static function readRefundOtpCode(): string
    {
        $code = isset($_REQUEST['paymenthood_otpcode']) ? (string) $_REQUEST['paymenthood_otpcode'] : '';
        $code = preg_replace('/\D/', '', $code);

        // Authenticator codes are 6 digits; 8 covers backup/recovery formats.
        if ($code === '' || strlen($code) < 6 || strlen($code) > 8) {
            return '';
        }

        return $code;
    }

    /**
     * Record that the admin UI should prompt for a code on this page render.
     */
    public static function flagRefund2fa(string $reason, int $invoiceId, string $message = ''): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[self::REFUND_2FA_SESSION_KEY] = [
            'reason'    => $reason,
            'invoiceId' => $invoiceId,
            'message'   => $message,
        ];
    }

    /**
     * Read and clear the prompt flag. One-shot: a reload must not re-prompt.
     */
    public static function consumeRefund2faFlag()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION[self::REFUND_2FA_SESSION_KEY])) {
            return null;
        }

        $flag = $_SESSION[self::REFUND_2FA_SESSION_KEY];
        unset($_SESSION[self::REFUND_2FA_SESSION_KEY]);

        return is_array($flag) ? $flag : null;
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
